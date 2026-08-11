<?php
header('Content-Type: text/plain');

$host = getenv('MYSQLHOST');
$port = getenv('MYSQLPORT');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');

echo "=== Updating User Names ===\n\n";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=paynex;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    // Get all earner users (excluding admin and real user)
    $users = $pdo->query("SELECT id, name FROM users WHERE role = 'earner' AND id > 3 ORDER BY id")->fetchAll();
    echo "Found " . count($users) . " users to update\n\n";
    
    // Diverse name pool - African, Indian, mixed with European surnames
    $names = [
        // Ghanaian names
        'Kwame Mensah', 'Ama Asante', 'Kofi Adu', 'Akua Boateng', 'Yaw Owusu',
        'Abena Osei', 'Kojo Frimpong', 'Adwoa Nyarko', 'Nana Appiah', 'Efua Darko',
        'Kwesi Addo', 'Afia Sarpong', 'Kwabena Ansah', 'Aba Williams', 'Yaa Manu',
        'Kwaku Asare', 'Ama Serwaa', 'Kofi Amoako', 'Akosua Baidoo', 'Yaw Boakye',
        'Abena Mensah', 'Kojo Tetteh', 'Adwoa Amoah', 'Nana Agyemang', 'Efua Asiedu',
        // Nigerian names
        'Chukwuemeka Obi', 'Ngozi Okonkwo', 'Emeka Nwosu', 'Adaeze Eze', 'Chinedu Ike',
        'Oluwaseun Adebayo', 'Chinelo Nwankwo', 'Emeka Okoro', 'Ngozi Adichie', 'Obinna Agba',
        'Chidinma Okafor', 'Emeka Uche', 'Nneka Obi', 'Chukwudi Nnamdi', 'Adaeze Chukwu',
        'Oluwadamilola Bankole', 'Chinyere Uzoma', 'Emeka Okafor', 'Ngozi Olu', 'Obinna Chukwu',
        // Indian names
        'Rajesh Patel', 'Priya Sharma', 'Amit Singh', 'Deepika Nair', 'Vikram Kumar',
        'Ananya Reddy', 'Rahul Verma', 'Sneha Gupta', 'Arjun Mehta', 'Pooja Iyer',
        'Rohit Das', 'Neha Kapoor', 'Sanjay Mishra', 'Kavita Joshi', 'Vijay Singh',
        'Meera Rao', 'Suresh Pandey', 'Lakshmi Pillai', 'Anil Sharma', 'Divya Menon',
        // Other African names (Kenyan, Tanzanian, South African, etc.)
        'Jabari Odhiambo', 'Wanjiku Mwangi', 'Kwame Nkrumah', 'Fatima Hassan', 'Oluwatosin Adeyemi',
        'Thabo Mokoena', 'Zanele Dlamini', 'Kofi Annan', 'Amina Mohammed', 'Tendai Chipungu',
        'Chimamanda Adichie', 'Olumide Bankole', 'Wambui Kamau', 'Ndidi Okonkwo', 'Tunde Bakare',
        'Lerato Molefe', 'Ayanda Sithole', 'Blessing Eze', 'Nkosazana Dlamini', 'Palesa Mokoena',
        // Mixed African + European/American surnames
        'Kwame Johnson', 'Ama Thompson', 'Kofi Williams', 'Akua Brown', 'Yaw Davis',
        'Abena Wilson', 'Kojo Miller', 'Adwoa Moore', 'Nana Taylor', 'Efua Anderson',
        'Chukwu Michael', 'Ngozi Thomas', 'Emeka Jackson', 'Adaeze White', 'Chinedu Harris',
        'Oluwaseun Martin', 'Chinelo Garcia', 'Emeka Martinez', 'Nneka Robinson', 'Obinna Clark',
        'Rajesh Kumar', 'Priya Patel', 'Amit Shah', 'Deepika Singh', 'Vikram Gandhi',
        'Ananya Bose', 'Rahul Bose', 'Sneha Das', 'Arjun Malhotra', 'Pooja Chopra',
        'Jabari Smith', 'Wanjiku Jones', 'Thabo Brown', 'Zanele Davis', 'Fatima Wilson',
        'Tendai Moore', 'Lerato Taylor', 'Ayanda Thomas', 'Blessing Jackson', 'Palesa White',
    ];
    
    // Shuffle and assign unique names
    shuffle($names);
    $updateStmt = $pdo->prepare("UPDATE users SET name = :name WHERE id = :id");
    $updated = 0;
    
    foreach ($users as $i => $user) {
        if ($i < count($names)) {
            $updateStmt->execute([':name' => $names[$i], ':id' => $user['id']]);
            $updated++;
        }
    }
    
    echo "Updated $updated user names\n\n";
    
    // Show sample of updated names
    echo "Sample updated names:\n";
    $sample = $pdo->query("SELECT name FROM users WHERE role = 'earner' AND id > 3 ORDER BY RAND() LIMIT 20")->fetchAll();
    foreach ($sample as $row) echo "  - " . $row['name'] . "\n";
    
    echo "\n=== Done! ===\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage();
}
