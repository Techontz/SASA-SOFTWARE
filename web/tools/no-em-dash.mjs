/**
 * The owner's rule: no em-dash (U+2014) anywhere a user can see it.
 *
 *   node tools/no-em-dash.mjs        (or: npm run lint:copy)
 *
 * Scans every .ts/.tsx file under web/src, and the mobile app's src/ and app/
 * when they exist. Each file is parsed with the TypeScript compiler, so
 * comments (line, block and JSX) are ignored and only code is checked: string
 * literals, template literals, JSX text and anything else outside a comment.
 * Exits 1 and lists file:line for every em-dash it finds.
 */
import { readdirSync, readFileSync, statSync, existsSync } from "node:fs";
import { join, relative, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import ts from "typescript";

const here = fileURLToPath(new URL(".", import.meta.url));
const webRoot = resolve(here, "..");
const repoRoot = resolve(webRoot, "..");
const roots = [
  join(webRoot, "src"),
  join(repoRoot, "app", "src"),
  join(repoRoot, "app", "app"),
].filter((dir) => existsSync(dir));

const EM_DASH = "—";

function* walk(dir) {
  for (const name of readdirSync(dir)) {
    if (name === "node_modules" || name.startsWith(".")) continue;
    const path = join(dir, name);
    if (statSync(path).isDirectory()) yield* walk(path);
    else if (/\.(ts|tsx)$/.test(name) && !name.endsWith(".d.ts")) yield path;
  }
}

/** Blank out every comment, keeping offsets (and so line numbers) intact. */
function stripComments(text, path) {
  const source = ts.createSourceFile(path, text, ts.ScriptTarget.Latest, true,
    path.endsWith(".tsx") ? ts.ScriptKind.TSX : ts.ScriptKind.TS);
  const ranges = new Map();
  const collect = (list) => list?.forEach((r) => ranges.set(r.pos, r.end));
  // Every token, punctuation included, so a comment after a comma is found too.
  const visit = (node) => {
    if (node.kind !== ts.SyntaxKind.JsxText) {
      collect(ts.getLeadingCommentRanges(text, node.getFullStart()));
      collect(ts.getTrailingCommentRanges(text, node.getEnd()));
    }
    for (const child of node.getChildren(source)) visit(child);
  };
  visit(source);
  collect(ts.getLeadingCommentRanges(text, source.endOfFileToken.getFullStart()));
  const chars = text.split("");
  for (const [pos, end] of ranges) {
    for (let i = pos; i < end; i++) if (chars[i] !== "\n") chars[i] = " ";
  }
  return chars.join("");
}

const failures = [];
for (const root of roots) {
  for (const path of walk(root)) {
    const text = readFileSync(path, "utf8");
    if (!text.includes(EM_DASH)) continue;
    const code = stripComments(text, path);
    code.split("\n").forEach((line, i) => {
      if (line.includes(EM_DASH)) {
        failures.push(`${relative(repoRoot, path)}:${i + 1}: ${text.split("\n")[i].trim()}`);
      }
    });
  }
}

if (failures.length) {
  console.error(`Em-dash found in user-visible code (${failures.length}). Rewrite the sentence instead:\n`);
  for (const f of failures) console.error("  " + f);
  process.exit(1);
}
console.log(`No em-dashes outside comments (${roots.map((r) => relative(repoRoot, r)).join(", ")}).`);
