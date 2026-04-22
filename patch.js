const fs = require('fs');
const file = 'c:/AppServ/www/borrowing-system/borrow.php';
let content = fs.readFileSync(file, 'utf8');

const regex = /if \(\\['resource_type'\] === 'equipment'\) \{\s+\ = mysqli_prepare\([\s\S]+?mysqli_stmt_close\(\\);\s+\}/m;

const replacement = if (\\['resource_type'] === 'equipment') {
                    \\ = mysqli_prepare(
                        \\,
                        'SELECT COUNT(*) AS available_count FROM equipments WHERE equipment_code = ? AND operation_status = 1 FOR UPDATE'
                    );
                    \\ = mysqli_prepare(
                        \\,
                        'SELECT equipment_id FROM equipments WHERE equipment_code = ? AND operation_status = 1 ORDER BY equipment_id ASC LIMIT ?'
                    );
                    \\ = mysqli_prepare(
                        \\,
                        'INSERT INTO equipment_reservation_items (reservation_id, equipment_id, borrow_quantity) VALUES (?, ?, 1)'
                    );
                    \\ = mysqli_prepare(
                        \\,
                        'UPDATE equipments SET operation_status = 2 WHERE equipment_id = ? AND operation_status = 1 AND ? <= NOW()'
                    );
                    if (!\\ || !\\ || !\\ || !\\) {
                        throw new RuntimeException('建立器材預約指令失敗：' . mysqli_error(\\));
                    }

                    foreach (\\ as \\) {
                        \\ = \\['code'];
                        \\ = (int)\\['quantity'];

                        mysqli_stmt_bind_param(\\, 's', \\);
                        mysqli_stmt_execute(\\);
                        \\ = mysqli_stmt_get_result(\\);
                        \\ = \\ ? mysqli_fetch_assoc(\\) : null;

                        \\ = \\ ? (int)\\['available_count'] : 0;
                        if (\\ < \\) {
                            throw new RuntimeException("器材 {\\} 目前可借用數量不足，無法送出申請。");
                        }

                        mysqli_stmt_bind_param(\\, 'si', \\, \\);
                        mysqli_stmt_execute(\\);
                        \\ = mysqli_stmt_get_result(\\);

                        \\ = [];
                        while (\\ = mysqli_fetch_assoc(\\)) {
                            \\[] = (int)\\['equipment_id'];
                        }

                        if (count(\\) < \\) {
                            throw new RuntimeException("器材 {\\} 實際可取得數量不足。");
                        }

                        foreach (\\ as \\) {
                            mysqli_stmt_bind_param(\\, 'ii', \\, \\);
                            mysqli_stmt_execute(\\);
                            
                            mysqli_stmt_bind_param(\\, 'is', \\, \\);
                            mysqli_stmt_execute(\\);
                        }
                    }
                    mysqli_stmt_close(\\);
                    mysqli_stmt_close(\\);
                    mysqli_stmt_close(\\);
                    mysqli_stmt_close(\\);
                };

if (regex.test(content)) {
    content = content.replace(regex, replacement);
    fs.writeFileSync(file, content, 'utf8');
    console.log("Success patched DB insertion node!");
} else {
    console.log("Regex not matched!");
}
