import sys 

f=open("api/index.php","r",encoding="utf-8") 
c=f.read() 
f.close()
search="migration_staff_training_v2" 
idx=c.find(search,19000) 
close_idx=c.find("}",idx) 
insert_at=close_idx+1
new_func=chr(10)+"// v3: rename staff-training-videos to staff-training so the .htaccess slug route works."+chr(10)+"// The JS slug parser extracts staff-training from /links/staff-training/ but the DB"+chr(10)+"// had staff-training-videos, causing 404 on the public page."+chr(10)+"function migrate_staff_training_v3(SQLite3 $db): void {"+chr(10)+"    if ($db->querySingle(\"SELECT value FROM settings WHERE key='migration_staff_training_v3'\") === '1') return;"+chr(10)+"    $oldSlug = 'staff-training-videos';"+chr(10)+"    $newSlug = 'staff-training';"+chr(10)+"    $pageId = (int)$db->querySingle(\"SELECT id FROM pages WHERE slug=?\", [$oldSlug]);"+chr(10)+"    if (!$pageId) return;"+chr(10)+"    $db->exec(\"UPDATE pages SET slug='$newSlug', updated_at=datetime('now') WHERE id=$pageId\");"+chr(10)+"    $db->exec(\"INSERT OR REPLACE INTO settings (key,value,updated_at) VALUES ('migration_staff_training_v3','1',datetime('now'))\");"+chr(10)+"}"+chr(10)
c=c[:insert_at]+new_func+c[insert_at:] 
f=open("api/index.php","w",encoding="utf-8") 
f.write(c) 
f.close() 
print("Inserted v3 function") 
