import os

path = r'c:\AppServ\www\borrowing-system\borrow.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

content = content.replace('<div class="es-item-icon">🎤</div>', '<div class="es-item-icon"><?php echo getEquipmentIcon([\'equipment_name\']); ?></div>')
content = content.replace('<div class="es-item-icon">📍</div>', '<div class="es-item-icon"><?php echo getSpaceIcon([\'space_name\']); ?></div>')

with open(path, 'w', encoding='utf-8') as f:
    f.write(content)
