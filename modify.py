f=open("api/index.php","rb")
c=f.read()
f.close()
CRLF=bytes([13,10])
Q=bytes([39])
old_preview = b"    header(" + Q + b"Content-Type: application/json; charset=utf-8" + Q + b");" + CRLF + b"    http_response_code(200);" + CRLF + b"    echo json_encode([" + Q + b"ok" + Q + b"=," + Q + b"preview" + Q + b"=," + Q + b"data" + Q + b"=," + Q + b"buttons" + Q + b"=," + Q + b"sections" + Q + b"=," + Q + b"settings" + Q + b"=;" + CRLF + b"    exit;"
new_preview = b"    \$noindex = in_array(\$page[" + Q + b"visibility" + Q + b"], [" + Q + b"unlisted" + Q + b"," + Q + b"staff_only" + Q + b"], true);" + CRLF + b"    header(" + Q + b"Content-Type: application/json; charset=utf-8" + Q + b");" + CRLF + b"    http_response_code(200);" + CRLF + b"    echo json_encode([" + Q + b"ok" + Q + b"=," + Q + b"preview" + Q + b"=," + Q + b"data" + Q + b"=," + Q + b"buttons" + Q + b"=," + Q + b"sections" + Q + b"=," + Q + b"settings" + Q + b"=," + Q + b"noindex" + Q + b"=;" + CRLF + b"    exit;"
print("preview old in content:", old_preview in c)
count1 = c.count(old_preview)
print("preview count:", count1)
c = c.replace(old_preview, new_preview, 1)
print("preview replaced")
old_links = b"    header(" + Q + b"Content-Type: application/json; charset=utf-8" + Q + b");" + CRLF + b"    http_response_code(200);" + CRLF + b"    echo json_encode([" + Q + b"ok" + Q + b"=," + Q + b"data" + Q + b"=," + Q + b"buttons" + Q + b"=," + Q + b"sections" + Q + b"=," + Q + b"settings" + Q + b"=;" + CRLF + b"    exit;"
new_links = b"    \$noindex = in_array(\$page[" + Q + b"visibility" + Q + b"], [" + Q + b"unlisted" + Q + b"," + Q + b"staff_only" + Q + b"], true);" + CRLF + b"    header(" + Q + b"Content-Type: application/json; charset=utf-8" + Q + b");" + CRLF + b"    http_response_code(200);" + CRLF + b"    echo json_encode([" + Q + b"ok" + Q + b"=," + Q + b"data" + Q + b"=," + Q + b"buttons" + Q + b"=," + Q + b"sections" + Q + b"=," + Q + b"settings" + Q + b"=," + Q + b"noindex" + Q + b"=;" + CRLF + b"    exit;"
count2 = c.count(old_links)
print("links count:", count2)
c = c.replace(old_links, new_links, 1)
print("links replaced")
fw=open("api/index.php","wb")
fw.write(c)
fw.close()
print("Saved")