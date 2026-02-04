# 🧹 Universal HTML Tag Cleaner for OpenCart/ocStore

![PHP Version](https://img.shields.io/badge/PHP-7.0%2B-blue)
![Database](https://img.shields.io/badge/Database-MySQL%2FMariaDB-orange)
![License](https://img.shields.io/badge/License-MIT-green)
![OpenCart](https://img.shields.io/badge/OpenCart-2.x%20%7C%203.x%20%7C%204.x-red)

**English** | [Українська (детально)](README_UA.md)

A powerful tool for automatically removing HTML tags from `description` fields in OpenCart/ocStore databases. Supports both simple tags and complex selectors with attributes.

## 📋 Table of Contents

- [Features](#-features)
- [Requirements](#-requirements)
- [Installation](#-installation)
- [Usage](#-usage)
- [Tag Syntax](#-tag-syntax)
- [Examples](#-examples)
- [Security](#-security)
- [Technical Details](#-technical-details)
- [Troubleshooting](#-troubleshooting)
- [License](#-license)

## ✨ Features

- 🎯 **Flexible Search**: Support for simple tags and complex selectors with attributes
- 🔍 **Two-Step Process**: Scan first, then clean
- 📊 **Detailed Statistics**: Complete report for each table and column
- 👁️ **Preview Changes**: See before/after examples before applying
- ✅ **Selective Cleaning**: Choose specific tables to process
- 🚀 **Batch Processing**: Efficient handling of large datasets
- 🎨 **Modern Interface**: Intuitive web-based UI
- 🔐 **Auto-Configuration**: Reads settings from `config.php`
- 🌐 **Ukrainian Localization**: Fully localized Ukrainian interface

## 📦 Requirements

- PHP 7.0 or higher
- MySQL 5.6+ / MariaDB 10.0+
- PDO PHP extension
- OpenCart / ocStore 2.x, 3.x or 4.x
- Database access rights (SELECT, UPDATE)

## 🚀 Installation

### Step 1: Download the file

1. Download `html_tag_cleaner.php`
2. Place it in the **root directory** of your OpenCart/ocStore installation (where `config.php` is located)

```
/public_html/
├── admin/
├── catalog/
├── system/
├── config.php          ← must be here
├── index.php
└── html_tag_cleaner.php ← place file here
```

### Step 2: Set permissions

```bash
chmod 644 html_tag_cleaner.php
```

### Step 3: Create database backup

**⚠️ MANDATORY!** Create a database backup before using:

```bash
mysqldump -u username -p database_name > backup_$(date +%Y%m%d_%H%M%S).sql
```

Or via phpMyAdmin: `Export` → `Go`

## 📖 Usage

### Basic Workflow

1. **Access the script**
   ```
   https://your-domain.com/html_tag_cleaner.php
   ```

2. **Specify tags to remove**
   - Enter tags in the field (comma-separated)
   - Or use ready-made templates

3. **Scan database**
   - Click "🔍 Scan Database"
   - Review scan results
   - Check change examples

4. **Select tables**
   - All found tables are selected by default
   - You can choose specific tables to process
   - Use "Select All" / "Deselect All" buttons

5. **Clean**
   - Click "🚀 Start Cleaning"
   - Confirm action
   - Wait for completion

6. **Review report**
   - Check processing statistics
   - Review number of updated records

7. **Delete script**
   - **Always delete the file after use!**

### Interface

#### Tag Input Field
```
Specify HTML tags (comma-separated):
┌─────────────────────────────────────┐
│ span, strong, em, font              │
└─────────────────────────────────────┘
```

#### Quick Templates
- **🎨 Colored spans** - `span[style*="color"]`
- **📝 Text formatting** - `span, strong, em, b, i, u`
- **🔤 Old font tags** - `font`
- **📦 Block elements** - `div, p`

## 🎯 Tag Syntax

### 1. Simple Tags
Removes all occurrences of a tag, keeping content:

```
span
```
Result: `<span>text</span>` → `text`

### 2. Multiple Tags
Removes several different tags:

```
span, strong, em, b, i
```

### 3. Tags with Exact Attribute Match
Removes only tags with specific attribute value:

```
span[style="color: red"]
```
Result: 
- `<span style="color: red">text</span>` → `text` ✅
- `<span style="color: blue">text</span>` → remains ❌

### 4. Tags with Partial Attribute Match (Wildcard)
Removes tags where attribute **contains** specified text:

```
span[style*="color"]
```
Result:
- `<span style="color: red">text</span>` → `text` ✅
- `<span style="background-color: blue">text</span>` → `text` ✅
- `<span style="font-size: 14px">text</span>` → remains ❌

### 5. Complex Combinations
```
span[style*="color"], span[class="highlight"], strong, em
```

## 💡 Examples

### Example 1: Remove colored span tags
**Problem**: After import, product descriptions contain `<span style="color: #ff0000">` tags

**Solution**:
```
span[style*="color"]
```

**Before**:
```html
<p>This product is <span style="color: red">high quality</span> and available</p>
```

**After**:
```html
<p>This product is high quality and available</p>
```

### Example 2: Remove all formatting
**Problem**: Need to remove all text formatting

**Solution**:
```
span, strong, em, b, i, u, font
```

**Before**:
```html
<p><strong>Important!</strong> This product is <em>very</em> <u>good</u></p>
```

**After**:
```html
<p>Important! This product is very good</p>
```

### Example 3: Remove font tags from old descriptions
**Problem**: Database contains outdated `<font>` tags

**Solution**:
```
font
```

**Before**:
```html
<font face="Arial" size="3" color="black">Product description</font>
```

**After**:
```html
Product description
```

### Example 4: Remove specific class
**Problem**: After copying from Word, special spans with classes remain

**Solution**:
```
span[class="mso"]
```

## 🔒 Security

### Security Recommendations

1. **Backup**
   ```bash
   # Create backup before EVERY use
   mysqldump -u user -p database > backup.sql
   ```

2. **Access Restriction**
   ```apache
   # .htaccess - restrict access by IP
   <Files "html_tag_cleaner.php">
       Order Deny,Allow
       Deny from all
       Allow from 192.168.1.100
   </Files>
   ```

3. **Delete after use**
   ```bash
   # Delete file immediately after completion
   rm html_tag_cleaner.php
   ```

4. **Use in dev environment**
   - Test on a site copy first
   - Verify results on test data
   - Only then use on production

### Built-in Protection

The script has built-in safety mechanisms:
- ✅ Action validation (only 'scan' and 'clean')
- ✅ PDO with prepared statements (SQL injection protection)
- ✅ HTML escaping in output
- ✅ Transactions for batch processing
- ✅ Error handling with rollback

## 🔧 Technical Details

### Supported Tables

The script automatically finds ALL tables containing columns with `*description*` in the name:

Typical OpenCart tables:
- `oc_product_description`
- `oc_category_description`
- `oc_information_description`
- `oc_attribute_description`
- `oc_option_description`
- `oc_option_value_description`
- and others...

### Prefix Support

Automatically detects table prefixes:
- `oc_` (standard OpenCart)
- `ocs_` (ocStore)
- Any other custom prefix

### Primary Key Handling

Works correctly with:
- Simple PRIMARY KEY
- Composite (multi-column) keys
- UNIQUE keys
- Tables without primary keys

### Batch Processing

```php
$BATCH_SIZE = 100; // records per batch
```

Benefits:
- Reduced database load
- Lower memory usage
- Rollback capability on errors

### Tag Removal Algorithm

```php
1. HTML decode (convert &lt; to <)
2. Apply regex patterns
3. Iterative removal (up to 10 passes)
4. HTML encode back
```

## 🐛 Troubleshooting

### Error: "config.php file not found"

**Solution**: Place `html_tag_cleaner.php` in the same folder where `config.php` is located

```
✅ Correct:
/public_html/config.php
/public_html/html_tag_cleaner.php

❌ Incorrect:
/public_html/config.php
/public_html/admin/html_tag_cleaner.php
```

### Database connection error

**Solution**: Check database user permissions:

```sql
GRANT SELECT, UPDATE ON database_name.* TO 'user'@'localhost';
FLUSH PRIVILEGES;
```

### Not finding records with tags

**Possible causes**:
1. Tags specified with `< >` - **specify without them**: `span`, not `<span>`
2. Case matters for attributes: `style*="Color"` ≠ `style*="color"`
3. Spaces in tags: `span[style*="color"]` ✅ vs `span[ style*="color" ]` ❌

### Slow performance

**Optimization**:
1. Increase `$BATCH_SIZE` in code (line 407):
   ```php
   $BATCH_SIZE = 500; // instead of 100
   ```
2. Process tables in portions (select not all at once)
3. Add indexes on description columns (optional)

### Memory limit error

**Solution**: Increase memory limit in `php.ini`:

```ini
memory_limit = 512M
```

Or in code (add at the beginning):
```php
ini_set('memory_limit', '512M');
```

## 📊 Statistics & Reporting

### Scan Report Format

```
✓ Scanning completed!
Search tags: span[style*="color"]

┌─────────────────────────┐
│ Tables found:        5  │
│ Records to process: 234 │
└─────────────────────────┘

📋 oc_product_description
  └─ description: 127 records contain specified tags
     👁️ Change example (first record)
     
📋 oc_category_description
  └─ description: 45 records contain specified tags
```

### Clean Report Format

```
✅ Cleaning completed successfully!

┌───────────────────────┐
│ Processed:    234     │
│ Updated:      234     │
│ Time:         2.45 sec│
└───────────────────────┘

Report:
📋 oc_product_description
  └─ description: Processed: 127, Updated: 127
```

## 🎨 Customization

### Change Batch Size

```php
// Line 407
$BATCH_SIZE = 100; // change to desired value
```

### Add Custom Templates

```javascript
// Add in HTML after existing templates:
<button type="button" onclick="applyTemplate('your, tags, here')" class="template-btn">
    🎯 Your Template
</button>
```

### Change Design

All styles are in the `<style>` section. Modify CSS variables:

```css
:root {
    --primary: #6366f1;        /* Primary color */
    --success: #10b981;        /* Success color */
    --danger: #ef4444;         /* Error color */
}
```

## 📝 FAQ

**Q: Is tag content deleted?**  
A: No, only the tags themselves are removed. Content remains.

**Q: Can changes be undone?**  
A: No, so always make a backup before use.

**Q: How many records can be processed at once?**  
A: Virtually unlimited, thanks to batch processing. Tested on 50000+ records.

**Q: Does it work with Cyrillic?**  
A: Yes, full UTF-8 support.

**Q: Can it be used on shared hosting?**  
A: Yes, if you have PDO access and UPDATE rights in the database.

**Q: Is it safe to use on production?**  
A: Yes, but only after testing on a dev copy and creating a backup.

## 🤝 Contributing

If you found a bug or have ideas for improvement:

1. Create an Issue with detailed description
2. Or submit a Pull Request with fixes
3. Share your experience

## 📄 License

MIT License

Copyright (c) 2026

Permission is hereby granted, free of charge, to use, copy, modify, and distribute this software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT ANY WARRANTIES.

## ⚠️ Disclaimer

- Author is not responsible for data loss
- Always make a backup before use
- Test in dev environment before production
- Use at your own risk

## 📞 Support

If you need help:
- 🐛 Create an Issue for bugs
- 💡 Create a Discussion for questions
- ⭐ Star the project if it's useful!

---

**Made with ❤️ for OpenCart/ocStore community**

*Last updated: February 2026*
