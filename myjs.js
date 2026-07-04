const fs=require('fs') 
const c=fs.readFileSync('api/index.php') 
fs.writeFileSync('result.txt','len:'+c.length) 
