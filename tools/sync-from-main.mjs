#!/usr/bin/env node
/**
 * 从主仓库同步 backend 到飞牛部署仓库
 *
 * 用法：
 *   node tools/sync-from-main.mjs <主仓库路径> [git ref]
 *
 * 说明：
 *   - 只用主仓库已提交的内容（git archive），不会带上本机 .env / runtime / uploads
 *   - 会整体替换本仓库的 backend/，不会改动 Dockerfile 与 docker/ 配置
 *   - 同步后请自行检查 diff、提交并打标签
 *
 * 示例：
 *   node tools/sync-from-main.mjs ../cert-expiry-manager HEAD
 */

import { execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const mainRepo = process.argv[2];
const ref = process.argv[3] || 'HEAD';

if (!mainRepo) {
  console.error('用法：node tools/sync-from-main.mjs <主仓库路径> [git ref]');
  process.exit(1);
}

const mainRoot = resolve(mainRepo);
if (!existsSync(join(mainRoot, '.git'))) {
  console.error(`不是 Git 仓库：${mainRoot}`);
  process.exit(1);
}
if (mainRoot === repoRoot) {
  console.error('主仓库不能是当前仓库');
  process.exit(1);
}

const git = (args, options = {}) => execFileSync('git', ['-C', mainRoot, ...args], {
  encoding: 'utf8',
  ...options,
});

console.log(`主仓库：${mainRoot}`);
console.log(`同步版本：${ref}`);

// 读取主程序版本号
let version = '';
try {
  const upgrade = git(['show', `${ref}:backend/app/service/UpgradeService.php`]);
  const match = upgrade.match(/CURRENT_VERSION\s*=\s*'v?([0-9.]+)'/);
  if (match) version = match[1];
} catch {
  // 版本号仅用于展示，读取失败不阻断同步
}

const tmpDir = mkdtempSync(join(tmpdir(), 'cert-fnos-sync-'));
const archive = join(tmpDir, 'backend.tar');

try {
  console.log('正在导出主仓库 backend ...');
  git(['archive', '--format=tar', '-o', archive, ref, 'backend']);

  console.log('正在替换本仓库 backend ...');
  rmSync(join(repoRoot, 'backend'), { recursive: true, force: true });
  execFileSync('tar', ['-xf', archive, '-C', repoRoot], { stdio: 'inherit' });

  if (version) {
    writeFileSync(join(repoRoot, 'VERSION'), `${version}\n`, 'utf8');

    const readmePath = join(repoRoot, 'README.md');
    const readme = readFileSync(readmePath, 'utf8');
    const updated = readme.replace(/(主程序版本：v)[0-9.]+/g, `$1${version}`);
    if (updated !== readme) {
      writeFileSync(readmePath, updated, 'utf8');
    }
  }

  console.log('同步完成。');
  console.log(`  版本：${version || '未知'}`);
  console.log('后续：');
  console.log('  git status');
  console.log('  git diff --stat');
  console.log('  git add -A && git commit -m "chore: 同步主程序 <版本>"');
  console.log('  git tag v<版本> && git push origin main --tags');
} finally {
  rmSync(tmpDir, { recursive: true, force: true });
}
