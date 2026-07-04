const fs = require('fs');
const lines = fs.readFileSync('api/index.php', 'utf8').split('\\n');
const out = [];
const newFunc = ["","// v3: rename staff-training-videos to staff-training so the .htaccess slug route works.","// The JS slug parser extracts 'staff-training' from /links/staff-training/ but the DB","// had 'staff-training-videos', causing 404 on the public page.","function migrate_staff_training_v3(SQLite3 $db): void {","    if ($db->querySingle(\"SELECT value FROM settings WHERE key='migration_staff_training_v3'\") === '1') return;","    $oldSlug = 'staff-training-videos';","    $newSlug = 'staff-training';","    $pageId = (int)$db->querySingle(\"SELECT id FROM pages WHERE slug=?\", [$oldSlug]);","    if (!$pageId) return;","    $db->exec(\"UPDATE pages SET slug='+$newSlug+', updated_at=datetime('now') WHERE id='+$pageId\");","    $db->exec(\"INSERT OR REPLACE INTO settings (key,value,updated_at) VALUES ('migration_staff_training_v3','1',datetime('now'))\");","}"];
const noindexLine = "    $noindex = in_array($page['visibility'], ['unlisted','staff_only'], true);";
const headerLine = "    header('Content-Type: application/json; charset=utf-8');";
const codeLine = "    http_response_code(200);";
const previewEcho = "    echo json_encode(['ok'=>true,'preview'=>true,'data'=>['page'=>$page,'buttons'=>$buttons,'sections'=>$sections,'settings'=>$settings,'noindex'=>$noindex]]);";
const linksEcho = "    echo json_encode(['ok'=>true,'data'=>['page'=>$page,'buttons'=>$buttons,'sections'=>$sections,'settings'=>$settings,'noindex'=>$noindex]]);";
for (let i = 0; i < lines.length; i++) {
  const l = lines[i];
  out.push(l);
  if (i === 365 && l.trim() === '}') {
    for (const f of newFunc) { out.push(f.replace(/\\r$/, '')); }
    console.log('Inserted function');
  }
  if (i === 1517 || i === 1518) { out.pop(); continue; }
  if (i === 1519) {
    out.pop();
    out.push(noindexLine);
    out.push(headerLine);
    out.push(codeLine);
    out.push(previewEcho);
    console.log('Updated preview');
    continue;
  }
  if (i === 1537 || i === 1538) { out.pop(); continue; }
  if (i === 1539) {
    out.pop();
    out.push(noindexLine);
    out.push(headerLine);
    out.push(codeLine);
    out.push(linksEcho);
    console.log('Updated links');
    continue;
  }
}
fs.writeFileSync('api/index.php', out.join('\\n'), 'utf8');
console.log('Done. Total lines:', out.length);
