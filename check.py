import re

with open('api/index.php', 'r', encoding='utf-8') as f:
    lines = f.readlines()

q = chr(39) # #'
db = '$dB'  # $db->querySingle
query = '$db->querySingle(' + chr(34) + 'SELECT value FROM settings WHERE key=' + q + 'migration_staff_training_v3' + q + '" ) === ' + q + '1' + q + ') return;'
