with open("api/index.php", "r", encoding="utf-8") as f:
    content = f.read()
f.close()

Q=chr(39)
NL=chr(10)
GT=chr(62)
D=chr(36)
LBR=chr(91)
RBR=chr(93)
EQ="="+GT

old_preview = "    header(" + Q + "Content-Type: application/json; charset=utf-8" + Q + ");" + NL + "    http_response_code(200);" + NL + "    echo json_encode(" + LBR + Q + "ok" + Q + EQ + "true," + Q + "preview" + Q + EQ + "true," + Q + "data" + Q + EQ + LBR + Q + "page" + Q + EQ + D + "page," + Q + "buttons" + Q + EQ + D + "buttons," + Q + "sections" + Q + EQ + D + "sections," + Q + "settings" + Q + EQ + D + "settings" + RBR + RBR + ");" + NL + "    exit;"
new_preview = "    " + D + "noindex = in_array(" + D + "page[" + Q + "visibility" + Q + "], " + LBR + Q + "unlisted" + Q + "," + Q + "staff_only" + Q + "], true);" + NL + "    header(" + Q + "Content-Type: application/json; charset=utf-8" + Q + ");" + NL + "    http_response_code(200);" + NL + "    echo json_encode(" + LBR + Q + "ok" + Q + EQ + "true," + Q + "preview" + Q + EQ + "true," + Q + "data" + Q + EQ + LBR + Q + "page" + Q + EQ + D + "page," + Q + "buttons" + Q + EQ + D + "buttons," + Q + "sections" + Q + EQ + D + "sections," + Q + "settings" + Q + EQ + D + "settings," + Q + "noindex" + Q + EQ + D + "noindex" + RBR + RBR + ");" + NL + "    exit;"
old_links = "    header(" + Q + "Content-Type: application/json; charset=utf-8" + Q + ");" + NL + "    http_response_code(200);" + NL + "    echo json_encode(" + LBR + Q + "ok" + Q + EQ + "true," + Q + "data" + Q + EQ + LBR + Q + "page" + Q + EQ + D + "page," + Q + "buttons" + Q + EQ + D + "buttons," + Q + "sections" + Q + EQ + D + "sections," + Q + "settings" + Q + EQ + D + "settings" + RBR + RBR + ");" + NL + "    exit;"
new_links = "    " + D + "noindex = in_array(" + D + "page[" + Q + "visibility" + Q + "], " + LBR + Q + "unlisted" + Q + "," + Q + "staff_only" + Q + "], true);" + NL + "    header(" + Q + "Content-Type: application/json; charset=utf-8" + Q + ");" + NL + "    http_response_code(200);" + NL + "    echo json_encode(" + LBR + Q + "ok" + Q + EQ + "true," + Q + "data" + Q + EQ + LBR + Q + "page" + Q + EQ + D + "page," + Q + "buttons" + Q + EQ + D + "buttons," + Q + "sections" + Q + EQ + D + "sections," + Q + "settings" + Q + EQ + D + "settings," + Q + "noindex" + Q + EQ + D + "noindex" + RBR + RBR + ");" + NL + "    exit;"

if old_preview in content:
    content = content.replace(old_preview, new_preview, 1)
    print("Preview updated")
else:
    print("Preview NOT found")

if old_links in content:
    content = content.replace(old_links, new_links, 1)
    print("Links updated")
else:
    print("Links NOT found")

with open("api/index.php", "w", encoding="utf-8") as f:
    f.write(content)
print("Saved")