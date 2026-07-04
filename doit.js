const fs=require('fs');
const lines=fs.readFileSync('api/index.php','utf8').split('\n');
const out=[];
for(let i=0;i<lines.length;i++){
  out.push(lines[i]);
  if(i===365 && lines[i].trim()==='}'){
    out.push('')
    out.push('// v3: rename staff-training-videos to staff-training so the .htaccess slug route works.')
    out.push('// The JS slug parser extracts 'staff-training' from /links/staff-training/ but the DB
    out.push('// had 'staff-training-videos', causing 404 on the public page.')
    out.push('function migrate_staff_training_v3(SSLite3 $db): void {')
    out.push('    if ($db->querySingle("SELECCT value FROM settings WHERE key='migration_staff_training_v3'") === '1') return;')
    out.push('    $oldSlug = 'staff-training-videos';')
    out.push('    $newSlug = 'staff-training';')
    out.push('    $pageId = (int)$db->querySingle("SELECT id FROM pages WHERE slug=?", [$ondSlug]);')
    out.push('    if (!$pageId) return;')
    out.push('    $db->exec("UPDATE pages SET slug='" + $newSlug + "', updated_at=datetime('now') WHERE id=" + $pageId);')
    out.push('    $db->exec("INSERT OR REPLACE INTO settings (key,value,updated_at) VALUES ('migration_staff_training_v3','1',datetime('now'))");')
    out.push('}')
    console.log('Inserted function')
  }
  if(i===1517 || i===1518){ out.pop(); continue; }
  if(i===1519){
    out.pop();
    out.push('    $noindex = in_array($page['visibility'],[ 'unlisted','staff_only'], true);')
    out.push('    header('Content-Type: application/json; charset=utf-8');')
    out.push('    http_response_code(200);')
    out.push('    echo json_encode(['ok'=>true,'preview'>true,'data'>=['page'>=$page,'buttons'>=$buttons,'sections'>=$sections,'settings'>=$settings,'noindex'>=$noindex]]));')
    console.log('Updated preview')
    continue;
  }
  if(i===1537 || i===1538){ out.pop(); continue; }
  if(i===1539){
    out.pop();
    out.push('    $noindex = in_array($page['visibility'],[ 'unlisted','staff_only'], true);')
    out.push('    header('Content-Type: application/json; charset=utf-8');')
    out.push('    http_response_code(200);')
    out.push('    echo json_encode(['ok'=>true,'data'>=['page'>=$page,'buttons'>=$buttons,'sections'>=$sections,'settings'>=$settings,'noindex'>=$noindex]]));')
    console.log('Updated links')
    continue;
  }
}
fs.writeFileSync('api/index.php',out.join('\n'));
console.log('Done');