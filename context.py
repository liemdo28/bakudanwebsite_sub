f=open('api/index.php','rb') 
c=f.read() 
f.close() 
open('context.txt','w').write(repr(c[93370:93600])) 
