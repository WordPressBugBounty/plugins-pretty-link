const fs = require('fs');
const path = require('path');
const Ajv = require("ajv/dist/2020")
const addFormats = require('ajv-formats');

const ajv = new Ajv({ allErrors: true });
addFormats(ajv);

const schemaPath = path.join(__dirname, '../public/v2/tools.schema.json');
const schema = JSON.parse(fs.readFileSync(schemaPath, 'utf8'));

function validateToolsJson() {
  const toolsJsonPath = path.join(__dirname, '../public/v2/tools.json');
  const toolsJson = JSON.parse(fs.readFileSync(toolsJsonPath, 'utf8'));

  const validate = ajv.compile(schema);
  const valid = validate(toolsJson);

  if (!valid) {
    console.error('Validation failed:');
    validate.errors.forEach(error => {
      const itemIndex = error.instancePath.match(/\/(plugins|themes)\/(\d+)/)?.[2];
      const itemType = error.instancePath.match(/\/(plugins|themes)\/(\d+)/)?.[1];
      const item = itemIndex ? toolsJson[itemType][itemIndex] : null;
      console.error(`\nError${item ? ` in ${itemType.slice(0, -1)} "${item.title}"` : ''}:`);
      console.error(`- Path: ${error.instancePath}`);
      console.error(`- Message: ${error.message}`);
      if (error.params) {
        console.error(`- Details:`, error.params);
      }
    });
    process.exit(1);
  }

  console.log('Validation passed!');
}

validateToolsJson();