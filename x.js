const fs=require("fs");
const c=fs.readFileSync("api/index.php","utf8");
const lines=c.split(String.fromCharCode(10));
const out=[];
for(let i=0;i<lines.length;i++){out.push(lines[i]);}
fs.writeFileSync("api/index.php",out.join(String.fromCharCode(10)).replace(String.fromCharCode(10),String.fromCharCode(13,10)));
console.log("Done");