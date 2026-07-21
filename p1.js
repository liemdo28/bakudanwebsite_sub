const fs=require("fs");
const c=fs.readFileSync("api/index.php","utf8");
const lines=c.split(String.fromCharCode(10));
const out=[];
for(let i=0;i<lines.length;i++){
out.push(lines[i]);
if(i===365&&lines[i].trim()==="}"){
out.push("\\n");
out.push("// v3: rename staff-training-videos to staff-training so the .htaccess slug route works.");
out.push("// The JS slug parser extracts staff-training from /links/staff-training/ but the DB");
out.push("// had staff-training-videos, causing 404 on the public page.");
out.push("function migrate_staff_training_v3(SQLite3 \\$db): void {");