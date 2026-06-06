const puppeteer = require('puppeteer');
const { Client } = require('pg');

const CITY = process.env.CITY || 'vorkuta';
const LIMIT_PER_CATEGORY = parseInt(process.env.LIMIT) || 12;

// Category mapping for 2GIS
const CATEGORIES = {
  'restorany-i-kafe': ['кафе', 'рестораны', 'бары', 'кофейни', 'пиццерии', 'суши'],
  'krasota': ['салоны красоты', 'парикмахерские', 'маникюр'],
  'medicina': ['поликлиники', 'стоматология', 'аптеки'],
  'sport': ['фитнес клубы', 'спортивные клубы', 'бассейны'],
  'obrazovanie': ['школы', 'детские сады', 'курсы'],
  'razvlecheniya': ['кинотеатры', 'музеи', 'развлечения'],
  'magaziny': ['магазины', 'супермаркеты'],
  'transport': ['автосервисы', 'шиномонтаж', 'автомойки'],
  'uslugi-dlya-biznesa': ['бухгалтерские услуги', 'юридические услуги'],
  'uslugi-dlya-doma': ['сантехники', 'электрики', 'уборка'],
  'nedvizhimost': ['агентства недвижимости'],
  'bary-i-kluby': ['бары', 'клубы', 'пабы']
};

async function parse2GISCategory(browser, city, categorySlug, searchTerms, limit) {
  const page = await browser.newPage();
  await page.setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36');
  await page.setViewport({ width: 1280, height: 800 });
  
  const results = [];
  
  for (const term of searchTerms) {
    if (results.length >= limit) break;
    
    const encodedTerm = encodeURIComponent(term);
    const url = `https://2gis.ru/${city}/search/${encodedTerm}`;
    
    console.log(`  Parsing: ${term}`);
    
    try {
      await page.goto(url, { waitUntil: 'networkidle2', timeout: 60000 });
      await page.waitForTimeout(5000);
      
      // Extract company links
      const companies = await page.evaluate((maxItems) => {
        const items = [];
        const cards = document.querySelectorAll('a[href*="/firm/"]');
        
        cards.forEach(card => {
          const name = card.textContent?.trim();
          const href = card.href;
          
          if (name && href && name.length > 2 && !items.find(i => i.name === name)) {
            items.push({ name, detail_url: href });
          }
        });
        
        return items.slice(0, maxItems);
      }, limit);
      
      console.log(`    Found ${companies.length} companies`);
      
      // Get details for each company
      for (const company of companies) {
        if (results.length >= limit) break;
        if (results.find(r => r.name === company.name)) continue;
        
        try {
          await page.goto(company.detail_url, { waitUntil: 'networkidle2', timeout: 30000 });
          await page.waitForTimeout(3000);
          
          const details = await page.evaluate(() => {
            const phoneLink = document.querySelector('a[href^="tel:"]');
            const phone = phoneLink ? phoneLink.getAttribute('href').replace('tel:', '') : null;
            
            const addressEl = document.querySelector('span[data-testid="address"]');
            const address = addressEl?.textContent?.trim();
            
            const websiteEl = document.querySelector('a[href^="http"]:not([href*="2gis.ru"])');
            const website = websiteEl?.href;
            
            return { phone, address, website };
          });
          
          results.push({
            name: company.name,
            detail_url: company.detail_url,
            phone: details.phone,
            address: details.address,
            website: details.website
          });
          
          if (details.phone) {
            console.log(`      ✓ ${company.name} | ${details.phone}`);
          } else {
            console.log(`      ✓ ${company.name} (no phone)`);
          }
          
        } catch (err) {
          console.log(`      ✗ Error: ${err.message}`);
        }
      }
      
    } catch (err) {
      console.log(`    ✗ Error: ${err.message}`);
    }
  }
  
  await page.close();
  return results.slice(0, limit);
}

async function saveToDatabase(companies, citySlug, categorySlug) {
  const client = new Client({
    host: process.env.DB_HOST || 'postgres',
    port: 5432,
    database: process.env.DB_NAME || 'yell_parser',
    user: process.env.DB_USER || 'parser_user',
    password: process.env.DB_PASSWORD || 'parser_pass'
  });
  
  await client.connect();
  
  // Get city ID
  const cityRes = await client.query('SELECT id FROM cities WHERE slug = $1', [citySlug]);
  if (cityRes.rows.length === 0) {
    console.log(`City ${citySlug} not found`);
    await client.end();
    return 0;
  }
  const cityId = cityRes.rows[0].id;
  
  // Get category ID
  const catRes = await client.query('SELECT id FROM categories WHERE slug = $1', [categorySlug]);
  if (catRes.rows.length === 0) {
    console.log(`Category ${categorySlug} not found`);
    await client.end();
    return 0;
  }
  const categoryId = catRes.rows[0].id;
  
  let saved = 0;
  
  for (const company of companies) {
    try {
      // Check for duplicate
      const existing = await client.query(
        'SELECT id FROM companies WHERE name = $1 AND city_id = $2',
        [company.name, cityId]
      );
      
      if (existing.rows.length > 0) {
        console.log(`      ⏭️ Skip (exists): ${company.name}`);
        continue;
      }
      
      // Insert
      const insertRes = await client.query(
        `INSERT INTO companies (name, address, phone, website, city_id, source, source_url, created_at, updated_at)
         VALUES ($1, $2, $3, $4, $5, '2gis', $6, NOW(), NOW())
         RETURNING id`,
        [company.name, company.address, company.phone, company.website, cityId, company.detail_url]
      );
      
      const companyId = insertRes.rows[0].id;
      
      // Link to category
      await client.query(
        'INSERT INTO company_categories (company_id, category_id) VALUES ($1, $2)',
        [companyId, categoryId]
      );
      
      saved++;
      
    } catch (err) {
      console.log(`      ✗ DB Error: ${err.message}`);
    }
  }
  
  await client.end();
  return saved;
}

async function main() {
  console.log(`=== 2GIS Parser for ${CITY} ===\n`);
  
  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });
  
  const summary = {};
  
  for (const [categorySlug, searchTerms] of Object.entries(CATEGORIES)) {
    console.log(`\n[${categorySlug}]`);
    
    const companies = await parse2GISCategory(browser, CITY, categorySlug, searchTerms, LIMIT_PER_CATEGORY);
    
    if (companies.length > 0) {
      const saved = await saveToDatabase(companies, CITY, categorySlug);
      summary[categorySlug] = { found: companies.length, saved };
      console.log(`  Saved: ${saved}/${companies.length}`);
    } else {
      summary[categorySlug] = { found: 0, saved: 0 };
      console.log(`  No companies found`);
    }
  }
  
  await browser.close();
  
  console.log(`\n=== Summary for ${CITY} ===`);
  let totalFound = 0;
  let totalSaved = 0;
  for (const [cat, data] of Object.entries(summary)) {
    console.log(`${cat}: ${data.saved}/${data.found}`);
    totalFound += data.found;
    totalSaved += data.saved;
  }
  console.log(`\nTotal: ${totalSaved}/${totalFound} saved`);
}

main().catch(err => {
  console.error('Fatal error:', err);
  process.exit(1);
});
