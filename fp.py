f=open("api/index.php","rb")
c=f.read()
f.close()
positions=[]
i=0
while True:
    i=c.find(b"echo json_encode",i+1)
    if i<0:break
    positions.append(i)
print(positions)