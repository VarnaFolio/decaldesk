#!/usr/bin/env node
/**
 * Builds a genuinely stripped-down "Free" version of the DecalDesk plugin
 * folder, for WordPress.org submission - removes everything between
 * /*! <fs_premium_only> * / ... /*! </fs_premium_only> * / markers
 * (inclusive), instead of relying on Freemius's dashboard auto-strip
 * (which didn't strip anything for this product as of 2026-07-14 -
 * unwrapped markers came back untouched in the downloaded "Free" zip
 * from Deployment).
 *
 * Usage: node build-free-zip.js [sourceDir] [outputDir]
 * Defaults: sourceDir=./decaldesk, outputDir=./decaldesk-free-build/decaldesk
 *
 * Requires the `archiver` package for zipping - if not installed, this
 * script only produces the stripped folder; zip it yourself (e.g. via
 * PowerShell Compress-Archive) from outputDir's parent.
 */
const fs = require('fs');
const path = require('path');

// Leading [ \t]* absorbs same-line indentation before the opening marker so
// a marker on its own indented line doesn't leave that indent behind to
// double up with the next (unwrapped fallback) line's own indent once the
// closing marker's trailing newline is also consumed.
const MARKER_RE = /[ \t]*\/\*!\s*<fs_premium_only>\s*\*\/[\s\S]*?\/\*!\s*<\/fs_premium_only>\s*\*\/\n?/g;
// 'tools' excluded so this dev-only script doesn't ship inside the plugin
// it builds; '.gitignore' is dev-repo bookkeeping, not plugin runtime.
// 'assets/marketplace' holds WP.org/Marketplace LISTING screenshots (for the
// plugin directory page) - not a runtime asset, and not meant to ship inside
// the distributed plugin zip at all.
const EXCLUDE_DIRS = new Set(['.git', 'tools', 'marketplace']);
const EXCLUDE_FILES = new Set(['.gitignore']);

// vendor/freemius ships its own dev/build tooling (composer, gulp, phpcs,
// its own README/CONTRIBUTING) that has no runtime role - scoped to that
// directory only, so this doesn't touch the plugin's own root README.md
// or package.json.
const VENDOR_FREEMIUS_EXCLUDE_DIRS = new Set(['.github', 'gulptasks', 'patches', '.phpstan']);
const VENDOR_FREEMIUS_EXCLUDE_FILES = new Set([
  '.editorconfig',
  '.example.env',
  '.gitattributes',
  'CONTRIBUTING.md',
  'README.md',
  'composer.json',
  'composer.lock',
  'gulpfile.js',
  'package-lock.json',
  'package.json',
  'phpcompat.xml',
  'phpcs.xml',
  'phpstan.neon',
]);
const STRIPPABLE_EXTENSIONS = new Set(['.php', '.js']);

function isInsideVendorFreemius(fullPath) {
  const normalized = fullPath.split(path.sep).join('/');
  return normalized.includes('/vendor/freemius/');
}

function copyRecursive(src, dest) {
  const stat = fs.statSync(src);
  const scoped = isInsideVendorFreemius(src);
  if (stat.isDirectory()) {
    if (EXCLUDE_DIRS.has(path.basename(src))) return;
    if (scoped && VENDOR_FREEMIUS_EXCLUDE_DIRS.has(path.basename(src))) return;
    fs.mkdirSync(dest, { recursive: true });
    for (const entry of fs.readdirSync(src)) {
      copyRecursive(path.join(src, entry), path.join(dest, entry));
    }
  } else {
    if (EXCLUDE_FILES.has(path.basename(src))) return;
    if (scoped && VENDOR_FREEMIUS_EXCLUDE_FILES.has(path.basename(src))) return;
    fs.copyFileSync(src, dest);
  }
}

function stripMarkedFiles(dir, root, stats) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      stripMarkedFiles(full, root, stats);
    } else if (STRIPPABLE_EXTENSIONS.has(path.extname(entry.name))) {
      const original = fs.readFileSync(full, 'utf8');
      let count = 0;
      const stripped = original.replace(MARKER_RE, () => {
        count++;
        return '';
      });
      if (count > 0) {
        fs.writeFileSync(full, stripped, 'utf8');
        stats.push({ file: path.relative(root, full), count });
      }
    }
  }
}

// Freemius requires the Free package's own bootstrap to declare
// is_premium => false (the Premium/Freemius-distributed package keeps
// is_premium => true) - WP.org reviewers explicitly check for this.
function setFreemiusFreeFlag(outputDir) {
  const mainFile = path.join(outputDir, 'decaldesk.php');
  if (!fs.existsSync(mainFile)) {
    console.warn('decaldesk.php not found - skipped is_premium flip.');
    return false;
  }

  const original = fs.readFileSync(mainFile, 'utf8');
  const flagRe = /('is_premium'\s*=>\s*)true(\s*,)/;

  if (!flagRe.test(original)) {
    console.warn("Could not find 'is_premium' => true, in decaldesk.php - is_premium NOT flipped, check manually.");
    return false;
  }

  let updated = original.replace(flagRe, '$1false$2');

  // Same reasoning for the JS-facing 'isPro' localize flag - no live
  // can_use_premium_code() call should remain anywhere in the Free source,
  // even for a client-side-only informational flag.
  const isProRe = /('isPro'\s*=>\s*)decaldesk_fs\(\)->can_use_premium_code\(\)(\s*,)/;
  if (isProRe.test(updated)) {
    updated = updated.replace(isProRe, '$1false$2');
  } else {
    console.warn("Could not find the 'isPro' => decaldesk_fs()->can_use_premium_code() line - check decaldesk.php manually.");
  }

  fs.writeFileSync(mainFile, updated, 'utf8');
  return true;
}

function main() {
  const sourceDir = path.resolve(process.argv[2] || 'decaldesk');
  const outputParent = path.resolve(process.argv[3] || 'decaldesk-free-build');
  const outputDir = path.join(outputParent, path.basename(sourceDir));

  if (!fs.existsSync(sourceDir)) {
    console.error(`Source directory not found: ${sourceDir}`);
    process.exit(1);
  }

  if (fs.existsSync(outputParent)) {
    fs.rmSync(outputParent, { recursive: true, force: true });
  }

  copyRecursive(sourceDir, outputDir);

  const stats = [];
  stripMarkedFiles(outputDir, outputDir, stats);

  console.log(`Stripped folder built at: ${outputDir}`);
  const totalBlocks = stats.reduce((sum, s) => sum + s.count, 0);
  console.log(`Stripped ${totalBlocks} fs_premium_only block(s) across ${stats.length} file(s):`);
  for (const s of stats) {
    console.log(`  - ${s.file}: ${s.count} block(s)`);
  }

  const flipped = setFreemiusFreeFlag(outputDir);
  console.log(flipped ? "Set is_premium => false in decaldesk.php (Free build)." : "is_premium flip FAILED - check decaldesk.php manually before submitting.");
}

main();
