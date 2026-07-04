const fs=require('fs')
const c=fs.readFileSync('api/index.php')
let idx=0
while(true){
idx=c.indexOf(Buffer.from('header(',idx+1))
if(idx<0)break
fs.appendFileSync('p3.txt',idx+String.fromCharCode(10))
}
