const fs = require("fs");
const lines = fs.readFileSync("api/index.php", "utf8").split("\n");
const out = [];
const Q = String.fromCharCode(39);
const newFunc = [
  "",
  "// v3: rename staff-training-videos to staff-training so the .htaccess slug route works.",
  "// The JS slug parser extracts staff-training from /links/staff-training/ but the DB",
  "// had staff-training-videos, causing 404 on the public page.",
  "function migrate_staff_training_v3(SQLite3 $db): void {",
  "    if ($db- value FROM settings WHERE key=" + Q + "migration_staff_training_v3" + Q + "" + String.fromCharCode(34) + ") === " + Q + "1" + Q + ") return;",
  "    $oldSlug = " + Q + "staff-training-videos" + Q + ";",
  "    $newSlug = " + Q + "staff-training" + Q + ";",
  "    $pageId = (int)$db- id FROM pages WHERE slug=?" + String.fromCharCode(34) + ", [$oldSlug]);",
  "    if (!$pageId) return;",
  "    $db- pages SET slug=" + Q + "$newSlug" + Q + ", updated_at=datetime(" + Q + "now" + Q + ") WHERE id=$pageId" + String.fromCharCode(34) + ");",
  "    $db- OR REPLACE INTO settings (key,value,updated_at) VALUES (" + Q + "migration_staff_training_v3" + Q + "," + Q + "1" + Q + ",datetime(" + Q + "now" + Q + "))" + String.fromCharCode(34) + ");",
  "}"
];
const noindexLine = "    $noindex = in_array($page[" + Q + "visibility" + Q + "], [" + Q + "unlisted" + Q + "," + Q + "staff_only" + Q + "], true);";
const headerLine = "    header(" + Q + "Content-Type: application/json; charset=utf-8" + Q + ");";
const codeLine = "    http_response_code(200);";
const previewEcho = "    echo json_encode([" + Q + "ok" + Q + "=," + Q + "preview" + Q + "=," + Q + "data" + Q + "=," + Q + "buttons" + Q + "=," + Q + "sections" + Q + "=," + Q + "settings" + Q + "=," + Q + "noindex" + Q + "=;";
const linksEcho = "    echo json_encode([" + Q + "ok" + Q + "=," + Q + "data" + Q + "=," + Q + "buttons" + Q + "=," + Q + "sections" + Q + "=," + Q + "settings" + Q + "=," + Q + "noindex" + Q + "=;";
for (let i = 0; i < lines.length; i++) {
  const l = lines[i];
  out.push(l);
  if (i === 365 && l.trim() === "}") {
    for (const f of newFunc) { out.push(f); }
    console.log("Inserted function");
  }
  if (i === 1517 || i === 1518) { out.pop(); continue; }
  if (i === 1519) {
    out.pop();
    out.push(noindexLine);
    out.push(headerLine);
    out.push(codeLine);
    out.push(previewEcho);
    console.log("Updated preview");
    continue;
  }
  if (i === 1537 || i === 1538) { out.pop(); continue; }
  if (i === 1539) {
    out.pop();
    out.push(noindexLine);
    out.push(headerLine);
    out.push(codeLine);
    out.push(linksEcho);
    console.log("Updated links");
    continue;
  }
}
fs.writeFileSync("api/index.php", out.join("\n"));
console.log("Done. Total lines:", out.length);
