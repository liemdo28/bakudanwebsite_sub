f=open("api/index.php","r",encoding="utf-8") 
c=f.read() 
f.close() 
print(len(c)) 
p="migration_staff_training_v2" 
idx=c.find(p,19000) 
print("found at",idx) 
print(c[idx:idx+100]) 
