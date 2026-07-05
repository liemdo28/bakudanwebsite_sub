import sys 
f=open('api/index.php','rb') 
c=f.read() 
f.close() 
idx=c.find(b'echo json_encode') 
while idx>=0: 
    open('positions.txt','a').write(str(idx)+chr(10)) 
    idx=c.find(b'echo json_encode',idx+1) 
