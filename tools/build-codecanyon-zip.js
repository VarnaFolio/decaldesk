#!/usr/bin/env node
/**
 * Packages the DecalDesk plugin folder for CodeCanyon distribution: copies
 * everything except dev-only tooling (PHPCS/PHPStan/PHPUnit config, the
 * composer files, tests/, this tools/ dir, marketplace listing screenshots,
 * .git) into a clean decaldesk/ folder, then zips it.
 *
 * Single-tier product (no Free/Pro split, no Freemius) - the whole feature
 * set ships in this one package.
 *
 * Usage: node build-codecanyon-zip.js [sourceDir] [outputDir]
 * Defaults: sourceDir=./decaldesk, outputDir=./decaldesk-build
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

// 'tools' excluded so this dev-only script doesn't ship inside the plugin
// it builds; '.gitignore' is dev-repo bookkeeping, not plugin runtime.
// 'assets/marketplace' holds listing screenshots - not a runtime asset.
// 'tests' - PHPUnit test suite, dev-only.
const EXCLUDE_DIRS = new Set(['.git', '.claude', '.phpunit.cache', 'tools', 'marketplace', 'tests']);
// PHPCS/PHPStan/PHPUnit dev tooling at the plugin root (packages live in
// tools/vendor/, already excluded via the 'tools' dir above) - config-only
// files, but still dev-only, not plugin runtime.
const EXCLUDE_FILES = new Set(['.gitignore', 'composer.json', 'composer.lock', 'phpcs.xml.dist', 'phpstan.neon.dist', 'phpunit.xml.dist']);

function copyRecursive(src, dest) {
  const stat = fs.statSync(src);
  if (stat.isDirectory()) {
    if (EXCLUDE_DIRS.has(path.basename(src))) return;
    fs.mkdirSync(dest, { recursive: true });
    for (const entry of fs.readdirSync(src)) {
      copyRecursive(path.join(src, entry), path.join(dest, entry));
    }
  } else {
    if (EXCLUDE_FILES.has(path.basename(src))) return;
    fs.copyFileSync(src, dest);
  }
}

function main() {
  const sourceDir = path.resolve(process.argv[2] || 'decaldesk');
  const outputParent = path.resolve(process.argv[3] || 'decaldesk-build');
  const outputDir = path.join(outputParent, path.basename(sourceDir));

  if (!fs.existsSync(sourceDir)) {
    console.error(`Source directory not found: ${sourceDir}`);
    process.exit(1);
  }

  if (fs.existsSync(outputParent)) {
    fs.rmSync(outputParent, { recursive: true, force: true });
  }

  copyRecursive(sourceDir, outputDir);
  console.log(`Packaged folder built at: ${outputDir}`);

  const zipPath = `${outputParent}.zip`;
  if (fs.existsSync(zipPath)) {
    fs.rmSync(zipPath);
  }

  execFileSync('zip', ['-r', '-q', zipPath, path.basename(sourceDir)], { cwd: outputParent, stdio: 'inherit' });
  console.log(`Zip created at: ${zipPath}`);
}

main();
