#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

// Get the current version from package.json
const packageJsonPath = path.join(__dirname, '..', 'package.json');
const packageJson = JSON.parse(fs.readFileSync(packageJsonPath, 'utf8'));
const currentVersion = packageJson.version;

// Parse version components
const versionParts = currentVersion.split('.');
const major = parseInt(versionParts[0]);
const minor = parseInt(versionParts[1]);
const patch = parseInt(versionParts[2]);

// Increment patch version
const newVersion = `${major}.${minor}.${patch + 1}`;

console.log(`Bumping version from ${currentVersion} to ${newVersion}`);

// Update package.json
packageJson.version = newVersion;
fs.writeFileSync(packageJsonPath, JSON.stringify(packageJson, null, '\t') + '\n');
console.log('✓ Updated package.json');

// Update readme.txt
const readmePath = path.join(__dirname, '..', 'readme.txt');
let readmeContent = fs.readFileSync(readmePath, 'utf8');
readmeContent = readmeContent.replace(
    /^Stable tag: .+$/m,
    `Stable tag: ${newVersion}`
);
fs.writeFileSync(readmePath, readmeContent);
console.log('✓ Updated readme.txt');

// Update pattern-builder.php
const pluginPath = path.join(__dirname, '..', 'pattern-builder.php');
let pluginContent = fs.readFileSync(pluginPath, 'utf8');
pluginContent = pluginContent.replace(
    /^\s*\*\s*Version:\s*.+$/m,
    ` * Version: ${newVersion}`
);
fs.writeFileSync(pluginPath, pluginContent);
console.log('✓ Updated pattern-builder.php');

console.log(`\nVersion bump complete! New version: ${newVersion}`);