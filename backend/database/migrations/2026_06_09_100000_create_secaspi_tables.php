<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        DB::unprepared("
            CREATE TABLE users (
                user_id SERIAL PRIMARY KEY,
                full_name VARCHAR(150) NOT NULL,
                email VARCHAR(150) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                phone VARCHAR(20),
                role VARCHAR(20) DEFAULT 'user',
                status VARCHAR(20) DEFAULT 'active',
                email_verified BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE user_profiles (
                profile_id SERIAL PRIMARY KEY,
                user_id INT,
                address TEXT,
                birthday DATE,
                gender VARCHAR(20),
                occupation VARCHAR(100),
                FOREIGN KEY (user_id) REFERENCES users(user_id)
            );

            CREATE TABLE animals (
                animal_id SERIAL PRIMARY KEY,
                name VARCHAR(100),
                species VARCHAR(50),
                breed VARCHAR(100),
                age INT,
                gender VARCHAR(20),
                size VARCHAR(20),
                weight DECIMAL(5,2),
                status VARCHAR(20) DEFAULT 'available',
                rescue_story TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE animal_photos (
                photo_id SERIAL PRIMARY KEY,
                animal_id INT,
                photo_url TEXT,
                is_main BOOLEAN DEFAULT FALSE,
                FOREIGN KEY (animal_id) REFERENCES animals(animal_id)
            );

            CREATE TABLE medical_records (
                record_id SERIAL PRIMARY KEY,
                animal_id INT,
                type VARCHAR(20),
                description TEXT,
                vet_name VARCHAR(150),
                cost DECIMAL(10,2),
                record_date DATE,
                notes TEXT,
                FOREIGN KEY (animal_id) REFERENCES animals(animal_id)
            );

            CREATE TABLE vaccinations (
                vaccine_id SERIAL PRIMARY KEY,
                animal_id INT,
                vaccine_name VARCHAR(150),
                date_given DATE,
                next_due DATE,
                FOREIGN KEY (animal_id) REFERENCES animals(animal_id)
            );

            CREATE TABLE adoption_applications (
                application_id SERIAL PRIMARY KEY,
                user_id INT,
                animal_id INT,
                reference_no VARCHAR(50) UNIQUE,
                status VARCHAR(20) DEFAULT 'pending',
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
                foster_id SERIAL PRIMARY KEY,
                user_id INT,
                animal_id INT,
                status VARCHAR(20) DEFAULT 'pending',
                start_date DATE,
                end_date DATE,
                notes TEXT,
                FOREIGN KEY (user_id) REFERENCES users(user_id),
                FOREIGN KEY (animal_id) REFERENCES animals(animal_id)
            );

            CREATE TABLE donations (
                donation_id SERIAL PRIMARY KEY,
                user_id INT,
                reference_no VARCHAR(100),
                amount DECIMAL(10,2),
                payment_method VARCHAR(20),
                proof_image TEXT,
                status VARCHAR(20) DEFAULT 'pending',
                donated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(user_id)
            );

            CREATE TABLE rescue_reports (
                report_id SERIAL PRIMARY KEY,
                reporter_name VARCHAR(150),
                contact_number VARCHAR(50),
                location TEXT,
                latitude DECIMAL(10,8),
                longitude DECIMAL(11,8),
                description TEXT,
                urgency VARCHAR(20),
                status VARCHAR(20) DEFAULT 'pending',
                photo_url TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE volunteers (
                volunteer_id SERIAL PRIMARY KEY,
                user_id INT,
                availability VARCHAR(100),
                hours_rendered INT DEFAULT 0,
                performance_notes TEXT,
                FOREIGN KEY (user_id) REFERENCES users(user_id)
            );

            CREATE TABLE volunteer_tasks (
                task_id SERIAL PRIMARY KEY,
                volunteer_id INT,
                task_name VARCHAR(150),
                status VARCHAR(20) DEFAULT 'assigned',
                assigned_date DATE,
                FOREIGN KEY (volunteer_id) REFERENCES volunteers(volunteer_id)
            );

            CREATE TABLE notifications (
                notification_id SERIAL PRIMARY KEY,
                user_id INT,
                message TEXT,
                is_read BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(user_id)
            );

            CREATE TABLE audit_logs (
                log_id SERIAL PRIMARY KEY,
                user_id INT,
                action TEXT,
                table_affected VARCHAR(100),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(user_id)
            );

            CREATE TABLE saved_animals (
                saved_id SERIAL PRIMARY KEY,
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
