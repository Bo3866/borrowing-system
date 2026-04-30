const fs = require('fs');

let content = fs.readFileSync('borrow.php', 'utf8');

const functions = "
function getEquipmentIcon(\\) {
    if (mb_strpos(\\, '麥克風') !== false) return '🎤';
    if (mb_strpos(\\, '擴音') !== false || mb_strpos(\\, '音響') !== false || mb_strpos(\\, '喊話器') !== false) return '🔊';
    if (mb_strpos(\\, '相機') !== false || mb_strpos(\\, '攝影機') !== false) return '📷';
    if (mb_strpos(\\, '腳架') !== false) return '🔭';
    if (mb_strpos(\\, '布幕') !== false || mb_strpos(\\, '投影') !== false) return '📽️';
    if (mb_strpos(\\, '鋼琴') !== false) return '🎹';
    if (mb_strpos(\\, '看板') !== false) return '📋';
    if (mb_strpos(\\, '桌') !== false) return '🪚';
    if (mb_strpos(\\, '椅') !== false) return '🪑';
    if (mb_strpos(\\, '帳') !== false) return '⛺';
    if (mb_strpos(\\, '警示') !== false || mb_strpos(\\, '交通') !== false) return '🚧';
    if (mb_strpos(\\, '旗') !== false) return '🚩';
    if (mb_strpos(\\, '燈') !== false) return '💡';
    if (mb_strpos(\\, '對講機') !== false) return '📻';
    if (mb_strpos(\\, '電') !== false || mb_strpos(\\, '線') !== false) return '🔌';
    if (mb_strpos(\\, '睡袋') !== false) return '🛌';
    if (mb_strpos(\\, '茶桶') !== false) return '🫖';
    return '📦';
}

function getSpaceIcon(\\) {
    if (mb_strpos(\\, '舞蹈') !== false) return '💃';
    if (mb_strpos(\\, '廣場') !== false || mb_strpos(\\, '戶外') !== false) return '🌳';
    if (mb_strpos(\\, '演講') !== false) return '🏛️';
    if (mb_strpos(\\, '會議室') !== false) return '🛋️';
    if (mb_strpos(\\, '電影城') !== false) return '🎬';
    if (mb_strpos(\\, '地下室') !== false) return '🏢';
    if (mb_strpos(\\, '擺攤') !== false) return '⛺';
    if (mb_strpos(\\, '教室') !== false) return '🏫';
    return '📍';
}
";

content = content.replace('session_start();', 'session_start();\\n' + functions);

content = content.replace('<div class="es-item-icon">🎤</div>', '<div class="es-item-icon"><?php echo getEquipmentIcon([\\'equipment_name\\']); ?></div>');
content = content.replace('<div class="es-item-icon">📍</div>', '<div class="es-item-icon"><?php echo getSpaceIcon([\\'space_name\\']); ?></div>');

fs.writeFileSync('borrow.php', content, 'utf8');
console.log('done');
