import os  
  
with open('api/index.php', 'r', encoding='utf-8') as f:  
    lines = f.readlines()  
  
print('Total lines:', len(lines))  
print('Line 366:', repr(lines[365]))  
