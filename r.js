const fs = require("fs");
const lines = fs.readFileSync("api/index.php", "utf8").split("\n");
const out = [];
for (let i = 0; i < lines.length; i++) {
  const l = lines[i];
  out.push(l);
}
console.log("lines:", lines.length);
