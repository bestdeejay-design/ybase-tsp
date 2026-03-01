const puppeteer = require('puppeteer');
const { Client } = require('pg');

const CITY = process.env.CITY || 'bologoe';
const CATEGORY = process.env.CATEGORY || 'kafe';
const LIMIT = parseInt(process.env.LIMIT) || 10;
const SOURCE = process.env.SOURCE || '2gis'; // '2gis' or 'yp'

// 2GIS category mapping
const CATEGORY_MAP_2GIS = {
  'kafe': '%D0%BA%D0%B0%D1%84%D0%B5',
  'restorany': '%D1%80%D0%B5%D1%81%D1%82%D0%BE%D1%80%D0%B0%D0%BD%D1%8B',
  'bary': '%D0%B1%D0%B0%D1%80%D1%8B'
};

async function parse2GIS() {
  console.log(`=== 2GIS Parser ===`);
  console.log(`City: ${CITY}`);
  console.log(`Category: ${CATEGORY}`);
  console.log(`Limit: ${LIMIT}\n`);

  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });

  const page = await browser.newPage();
  await page.setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
  await page.setViewport({ width: 1280, height: 800 });

  const catEncoded = CATEGORY_MAP_2GIS[CATEGORY] || CATEGORY;
  const url = `https://2gis.ru/${CITY}/search/${catEncoded}`;
  
  console.log(`Parsing: ${url}`);
  
  try {
    await page.goto(url, { waitUntil: 'networkidle2', timeout: 60000 });
    await page.waitForTimeout(5000); // Wait for JS to load
    
    // Extract company links
    const companies = await page.evaluate(() => {
      const items = [];
      const cards = document.querySelectorAll('a[href*="/firm/"]');
      
      cards.forEach(card => {
        const name = card.textContent?.trim();
        const href = card.href;
        
        if (name && href && name.length > 2 && !items.find(i => i.name === name)) {
          items.push({
            name: name,
            detail_url: href
          });
        }
      });
      
      return items.slice(0, 20);
    });

    console.log(`Found ${companies.length} companies`);
    
    // Get details for each company
    for (let i = 0; i < Math.min(companies.length, LIMIT); i++) {
      try {
        console.log(`  [${i+1}/${Math.min(companies.length, LIMIT)}] ${companies[i].name}`);
        await page.goto(companies[i].detail_url, { waitUntil: 'networkidle2', timeout: 30000 });
        await page.waitForTimeout(3000);
        
        const details = await page.evaluate(() => {
          // Get phone from tel: link
          const phoneLink = document.querySelector('a[href^="tel:"]');
          const phone = phoneLink ? phoneLink.getAttribute('href').replace('tel:', '') : null;
          
          // Get address
          const addressEl = document.querySelector('[data-testid="address"], [class*="address"]');
          const address = addressEl?.textContent?.trim();
          
          // Get website
          const websiteEl = document.querySelector('a[href^="http"]');
          const website = websiteEl?.href;
          
          return { phone, address, website };
        });
        
        companies[i].phone = details.phone;
        companies[i].address = details.address;
        companies[i].website = details.website;
        
        if (details.phone) {
          console.log(`    ✓ Phone: ${details.phone}`);
        }
        
      } catch (err) {
        console.log(`    ✗ Error: ${err.message}`);
      }
    }
    
    await browser.close();
    return companies.slice(0, LIMIT);
    
  } catch (err) {
    console.log(`Error: ${err.message}`);
    await browser.close();
    return [];
  }
}

async function parseYP() {
  console.log(`=== YP.RU Parser ===`);
  console.log(`City: ${CITY}`);
  console.log(`Category: ${CATEGORY}`);
  console.log(`Limit: ${LIMIT}\n`);

  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });

  const page = await browser.newPage();
  await page.setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
  await page.setViewport({ width: 1280, height: 800 });

  const results = [];
  const ypCategories = CATEGORY_MAP[CATEGORY] || [CATEGORY];

  for (const ypCat of ypCategories) {
    if (results.length >= LIMIT) break;

    const url = `https://${CITY}.yp.ru/list/${ypCat}/`;
    console.log(`Parsing: ${url}`);

    try {
      await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });
      
      // Wait for content
      await page.waitForSelector('body', { timeout: 10000 });
      
      // Check if we got real content or DDoS page
      const title = await page.title();
      if (title.includes('DDoS') || title.includes('Guard')) {
        console.log('  ⚠️ DDoS-Guard detected, waiting...');
        await page.waitForTimeout(5000);
        // Try again
        await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });
      }

      // Extract companies from list page
      const companies = await page.evaluate(() => {
        const items = [];
        const cards = document.querySelectorAll('.company-card, [class*="company"], .item');
        
        cards.forEach(card => {
          const nameEl = card.querySelector('h2, .company-name, .title');
          const name = nameEl?.textContent?.trim();
          const link = card.querySelector('a')?.href;
          
          if (name && name.length > 2) {
            items.push({
              name: name,
              detail_url: link || null,
            });
          }
        });
        
        return items;
      });

      console.log(`  Found ${companies.length} companies`);
      results.push(...companies);

      // Delay between requests
      await page.waitForTimeout(2000);

    } catch (err) {
      console.log(`  ✗ Error: ${err.message}`);
    }
  }

  // Get full phone numbers from detail pages
  console.log(`\n=== Getting full phone numbers ===`);
  for (let i = 0; i < results.length; i++) {
    if (results[i].detail_url) {
      try {
        console.log(`  [${i+1}/${results.length}] ${results[i].name}`);
        await page.goto(results[i].detail_url, { waitUntil: 'networkidle2', timeout: 30000 });
        await page.waitForTimeout(2000);
        
        // Try to find and click "show phone" button
        const phoneButton = await page.$('a[href*="tel:"], .phone-show, [class*="phone"] button, [onclick*="phone"]');
        if (phoneButton) {
          await phoneButton.click();
          await page.waitForTimeout(1000);
        }
        
        // Extract full phone
        const fullPhone = await page.evaluate(() => {
          const phoneEl = document.querySelector('a[href^="tel:"], .phone-full, [class*="phone"]');
          return phoneEl?.textContent?.trim() || phoneEl?.getAttribute('href')?.replace('tel:', '');
        });
        
        if (fullPhone && fullPhone.length > 5) {
          results[i].phone = fullPhone;
          console.log(`    ✓ Phone: ${fullPhone}`);
        } else {
          console.log(`    ✗ No phone found`);
        }
        
        // Extract address if missing
        if (!results[i].address) {
          const address = await page.evaluate(() => {
            const addrEl = document.querySelector('[class*="address"], .address');
            return addrEl?.textContent?.trim();
          });
          if (address) results[i].address = address;
        }
        
      } catch (err) {
        console.log(`    ✗ Error: ${err.message}`);
      }
    }
  }
  
  await browser.close();

  // Save to database
  const uniqueResults = results.slice(0, LIMIT);
  console.log(`\n=== Saving ${uniqueResults.length} companies ===`);

  const pgClient = new Client({
    host: process.env.DB_HOST || 'postgres',
    port: 5432,
    database: process.env.DB_NAME || 'yell_parser',
    user: process.env.DB_USER || 'parser_user',
    password: process.env.DB_PASSWORD || 'parser_pass'
  });

  await pgClient.connect();

  // Get city ID
  const cityRes = await pgClient.query('SELECT id FROM cities WHERE slug = $1', [CITY]);
  if (cityRes.rows.length === 0) {
    console.log(`City ${CITY} not found in database`);
    await pgClient.end();
    return;
  }
  const cityId = cityRes.rows[0].id;

  // Get category ID
  const catRes = await pgClient.query('SELECT id FROM categories WHERE slug = $1', [CATEGORY]);
  if (catRes.rows.length === 0) {
    console.log(`Category ${CATEGORY} not found`);
    await pgClient.end();
    return;
  }
  const categoryId = catRes.rows[0].id;

  let saved = 0;
  for (const company of uniqueResults) {
    try {
      // Check for duplicate
      const existing = await pgClient.query(
        'SELECT id FROM companies WHERE name = $1 AND city_id = $2',
        [company.name, cityId]
      );

      if (existing.rows.length > 0) {
        console.log(`  ⏭️ Skip (exists): ${company.name}`);
        continue;
      }

      // Insert
      const insertRes = await pgClient.query(
        `INSERT INTO companies (name, address, phone, city_id, source, source_url, created_at, updated_at)
         VALUES ($1, $2, $3, $4, 'yp.ru', $5, NOW(), NOW())
         RETURNING id`,
        [company.name, company.address, company.phone, cityId, company.detail_url]
      );

      const companyId = insertRes.rows[0].id;

      // Link to category
      await pgClient.query(
        'INSERT INTO company_categories (company_id, category_id) VALUES ($1, $2)',
        [companyId, categoryId]
      );

      saved++;
      console.log(`  ✓ Saved: ${company.name}`);

    } catch (err) {
      console.log(`  ✗ Error saving ${company.name}: ${err.message}`);
    }
  }

  await pgClient.end();
  console.log(`\n=== Done! Saved ${saved} companies ===`);
}

// Main
(async () => {
  try {
    let results;
    if (SOURCE === '2gis') {
      results = await parse2GIS();
    } else {
      results = await parseYP();
    }
    
    console.log(`\n=== Results ===`);
    console.log(`Total: ${results.length} companies`);
    results.forEach((r, i) => {
      console.log(`${i+1}. ${r.name} | ${r.phone || 'no phone'} | ${r.address || 'no address'}`);
    });
    
    process.exit(0);
  } catch (err) {
    console.error('Fatal error:', err);
    process.exit(1);
  }
})();
