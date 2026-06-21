<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        DB::unprepared("
            CREATE TABLE users (
                user_id INT AUTO_INCREMENT PRIMARY KEY,
                full_name VARCHAR(150) NOT NULL,
                email VARCHAR(150) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                phone VARCHAR(20),
                role ENUM('admin','volunteer','user') DEFAULT 'user',
                status ENUM('active','suspended','pending') DEFAULT 'active',
                email_verified TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE user_profiles (
                profile_id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                address TEXT,
                birthday DATE,
                gender VARCHAR(20),
                occupation VARCHAR(100),
                FOREIGN KEY (user_id) REFERENCES users(user_id)
            );

            CREATE TABLE animals (
                animal_id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100),
                species VARCHAR(50),
                breed VARCHAR(100),
                age INT,
                gender ENUM('male','female'),
                size ENUM('small','medium','large'),
                weight DECIMAL(5,2),
                status ENUM('available','adopted','fostered','medical','quarantine','archived') DEFAULT 'available',
                rescue_story TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE animal_photos (
                photo_id INT AUTO_INCREMENT PRIMARY KEY,
                animal_id INT,
                photo_url TEXT,
                is_main TINYINT(1) DEFAULT 0,
                FOREIGN KEY (animal_id) REFERENCES animals(animal_id)
            );

            CREATE TABLE medical_records (
                record_id INT AUTO_INCREMENT PRIMARY KEY,
                animal_id INT,
                type ENUM('vaccination','deworming','treatment','surgery','checkup','emergency'),
                description TEXT,
                vet_name VARCHAR(150),
                cost DECIMAL(10,2),
                record_date DATE,
                notes TEXT,
                FOREIGN KEY (animal_id) REFERENCES animals(animal_id)
            );

            CREATE TABLE vaccinations (
                vaccine_id INT AUTO_INCREMENT PRIMARY KEY,
                animal_id INT,
                vaccine_name VARCHAR(150),
                date_given DATE,
                next_due DATE,
                FOREIGN KEY (animal_id) REFERENCES animals(animal_id)
            );

            CREATE TABLE adoption_applications (
                application_id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                animal_id INT,
                reference_no VARCHAR(50) UNIQUE,
                status ENUM('pending','approved','declined','completed') DEFAULT 'pending',
                full_name VARCHAR(150),
                address TEXT,
                occupation VARCHAR(100),
                housing_type VARCHAR(100),
                pet_experience TEXT,
                reason TEXT,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(user_id),
                FOREIGN KEY (animal_id) REFERENCES animals(animal_id)
            );

            CREATE TABLE foster_applications (
                foster_id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                animal_id INT,
                status ENUM('pending','approved','active','completed','declined') DEFAULT 'pending',
                start_date DATE,
                end_date DATE,
                notes TEXT,
                FOREIGN KEY (user_id) REFERENCES users(user_id),
                FOREIGN KEY (animal_id) REFERENCES animals(animal_id)
            );

            CREATE TABLE donations (
                donation_id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                reference_no VARCHAR(100),
                amount DECIMAL(10,2),
                payment_method ENUM('gcash','cash','bank'),
                proof_image TEXT,
                status ENUM('pending','verified','rejected') DEFAULT 'pending',
                donated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(user_id)
            );

            CREATE TABLE rescue_reports (
                report_id INT AUTO_INCREMENT PRIMARY KEY,
                reporter_name VARCHAR(150),
                contact_number VARCHAR(50),
                location TEXT,
                latitude DECIMAL(10,8),
                longitude DECIMAL(11,8),
                description TEXT,
                urgency ENUM('low','medium','high','critical'),
                status ENUM('pending','assigned','in_progress','resolved') DEFAULT 'pending',
                photo_url TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE volunteers (
                volunteer_id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                availability VARCHAR(100),
                hours_rendered INT DEFAULT 0,
                performance_notes TEXT,
                FOREIGN KEY (user_id) REFERENCES users(user_id)
            );

            CREATE TABLE volunteer_tasks (
                task_id INT AUTO_INCREMENT PRIMARY KEY,
                volunteer_id INT,
                task_name VARCHAR(150),
                status ENUM('assigned','ongoing','completed') DEFAULT 'assigned',
                assigned_date DATE,
                FOREIGN KEY (volunteer_id) REFERENCES volunteers(volunteer_id)
            );

            CREATE TABLE notifications (
                notification_id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                message TEXT,
                is_read TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(user_id)
            );

            CREATE TABLE audit_logs (
                log_id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                action TEXT,
                table_affected VARCHAR(100),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(user_id)
            );

            CREATE TABLE saved_animals (
                saved_id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                animal_id INT,
                saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(user_id),
                FOREIGN KEY (animal_id) REFERENCES animals(animal_id)
            );
        ");
    }

    public function down(): void {
        DB::unprepared("
            DROP TABLE IF EXISTS saved_animals;
            DROP TABLE IF EXISTS audit_logs;
            DROP TABLE IF EXISTS notifications;
            DROP TABLE IF EXISTS volunteer_tasks;
            DROP TABLE IF EXISTS volunteers;
            DROP TABLE IF EXISTS rescue_reports;
            DROP TABLE IF EXISTS donations;
            DROP TABLE IF EXISTS foster_applications;
            DROP TABLE IF EXISTS adoption_applications;
            DROP TABLE IF EXISTS vaccinations;
            DROP TABLE IF EXISTS medical_records;
            DROP TABLE IF EXISTS animal_photos;
            DROP TABLE IF EXISTS animals;
            DROP TABLE IF EXISTS user_profiles;
            DROP TABLE IF EXISTS users;
        ");
    }
};