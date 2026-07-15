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

const MARKER_RE = /\/\*!\s*<fs_premium_only>\s*\*\/[\s\S]*?\/\*!\s*<\/fs_premium_only>\s*\*\/\n?/g;
// 'tools' excluded so this dev-only script doesn't ship inside the plugin
// it builds; '.gitignore' is dev-repo bookkeeping, not plugin runtime.
const EXCLUDE_DIRS = new Set(['.git', 'tools']);
const EXCLUDE_FILES = new Set(['.gitignore']);

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

function stripPhpFiles(dir, stats) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      stripPhpFiles(full, stats);
    } else if (entry.name.endsWith('.php')) {
      const original = fs.readFileSync(full, 'utf8');
      let count = 0;
      const stripped = original.replace(MARKER_RE, () => {
        count++;
        return '';
      });
      if (count > 0) {
        fs.writeFileSync(full, stripped, 'utf8');
        stats.push({ file: path.relative(dir, full), count });
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

  fs.writeFileSync(mainFile, original.replace(flagRe, '$1false$2'), 'utf8');
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
  stripPhpFiles(outputDir, stats);

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
