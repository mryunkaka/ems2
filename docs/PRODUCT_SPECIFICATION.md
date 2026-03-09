# Product Specification Document
# Emergency Medical System (EMS) v2.0

**Document Version:** 1.0  
**Date:** March 8, 2026  
**Status:** Active Development  
**Repository:** https://github.com/mryunkaka/ems2

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Product Overview](#2-product-overview)
3. [System Architecture](#3-system-architecture)
4. [Functional Specifications](#4-functional-specifications)
5. [Technical Specifications](#5-technical-specifications)
6. [Database Schema](#6-database-schema)
7. [API & Integrations](#7-api--integrations)
8. [Security Specifications](#8-security-specifications)
9. [UI/UX Design System](#9-uiux-design-system)
10. [Performance Requirements](#10-performance-requirements)
11. [Deployment & Infrastructure](#11-deployment--infrastructure)
12. [Roadmap & Future Enhancements](#12-roadmap--future-enhancements)

---

## 1. Executive Summary

### 1.1 Product Vision

The Emergency Medical System (EMS) is a comprehensive hospital management platform designed for **Roxwood Hospital** to streamline medical operations, pharmacy management, human resources, and administrative workflows. The system serves medical staff, administrators, and management with role-based access control.

### 1.2 Business Objectives

| Objective | Description |
|-----------|-------------|
| **Operational Efficiency** | Digitize and automate manual medical and administrative processes |
| **Data Centralization** | Single source of truth for patient records, pharmacy sales, and HR data |
| **Compliance** | Maintain audit trails and regulatory compliance for medical records |
| **Real-time Analytics** | Provide actionable insights through dashboards and reports |
| **Staff Empowerment** | Self-service portal for leave applications, reimbursements, and administrative tasks |

### 1.3 Target Users

| User Role | Description | Key Activities |
|-----------|-------------|----------------|
| **Medical Staff** | Doctors, nurses, paramedics | Patient records, medical procedures, prescriptions |
| **Pharmacy Staff** | Pharmacists, pharmacy assistants | Medicine dispensing, inventory, sales |
| **Administrators** | HR, finance, operations | User management, approvals, reports |
| **Management** | Department heads, directors | Analytics, strategic decisions, oversight |
| **Trainees** | Medical trainees | Limited access for learning purposes |

---

## 2. Product Overview

### 2.1 Core Modules

```
┌─────────────────────────────────────────────────────────────────┐
│                        EMS 2.0 MODULES                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐          │
│  │   MEDICAL    │  │   PHARMACY   │  │      HR      │          │
│  │              │  │              │  │              │          │
│  │ • Rekam Medis│  │ • Rekap      │  │ • Absensi    │          │
│  │ • Operasi    │  │   Farmasi    │  │ • Cuti &     │          │
│  │   Plastik    │  │ • Regulasi   │  │   Resign     │          │
│  │ • Layanan    │  │ • Konsumen   │  │ • Jabatan    │          │
│  │   EMS        │  │ • Ranking    │  │ • Gaji       │          │
│  └──────────────┘  └──────────────┘  └──────────────┘          │
│                                                                 │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐          │
│  │  FINANCE     │  │  ADMIN       │  │   SYSTEM     │          │
│  │              │  │              │  │              │          │
│  │ • Reimburse- │  │ • Event      │  │ • Dashboard  │          │
│  │   ment       │  │   Management │  │ • Settings   │          │
│  │ • Konsumsi   │  │ • Surat &    │  │ • User       │          │
│  │   Restoran   │  │   Notulen    │  │   Management │          │
│  └──────────────┘  └──────────────┘  └──────────────┘          │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 2.2 Module Descriptions

#### Medical Module
| Feature | Description | Status |
|---------|-------------|--------|
| **Rekam Medis** | Electronic medical records with patient data, KTP verification, MRI uploads, and HTML-rich medical reports | ✅ Live |
| **Operasi Plastik** | Plastic surgery case management and documentation | ✅ Live |
| **Layanan EMS** | General medical services catalog and tracking | ✅ Live |
| **Regulasi Medis** | Medical compliance and regulation documentation | ✅ Live |

#### Pharmacy Module
| Feature | Description | Status |
|---------|-------------|--------|
| **Rekap Farmasi** | Pharmacy sales recap with analytics (medicines, items, packages) | ✅ Live |
| **Regulasi Farmasi** | Pharmacy regulations and standard operating procedures | ✅ Live |
| **Konsumen** | Consumer/patient database and history | ✅ Live |
| **Ranking** | Performance rankings and leaderboards | ✅ Live |

#### HR Module
| Feature | Description | Status |
|---------|-------------|--------|
| **Absensi EMS** | Web-based attendance and working hours tracking | ✅ Live |
| **Cuti & Resign** | Leave and resignation application workflow with approval chain | ✅ Live |
| **Pengajuan Jabatan** | Position promotion application and requirements | ✅ Live |
| **Gaji** | Salary management and bonus calculation | ✅ Live |

#### Finance Module
| Feature | Description | Status |
|---------|-------------|--------|
| **Reimbursement** | Medical expense reimbursement claims and processing | ✅ Live |
| **Konsumsi Restoran** | Restaurant meal consumption tracking | ✅ Live |

#### Admin Module
| Feature | Description | Status |
|---------|-------------|--------|
| **Event Management** | Hospital event planning and participant management | ✅ Live |
| **Surat & Notulen** | Official letter and minutes generation | ✅ Live |
| **Validasi** | Document and request validation workflow | ✅ Live |
| **Manage Users** | User account and permission management | ✅ Live |

---

## 3. System Architecture

### 3.1 High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                         CLIENT LAYER                            │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐             │
│  │   Desktop   │  │   Mobile    │  │   Tablet    │             │
│  │   Browser   │  │   Browser   │  │   Browser   │             │
│  └─────────────┘  └─────────────┘  └─────────────┘             │
│                    (Responsive Web App)                         │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      PRESENTATION LAYER                         │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  PHP Views (dashboard/*.php, partials/*.php)            │   │
│  │  • Tailwind CSS styling                                  │   │
│  │  • Alpine.js interactivity                               │   │
│  │  • DataTables for tabular data                           │   │
│  │  • Chart.js for analytics                                │   │
│  └─────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      APPLICATION LAYER                          │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐ │
│  │  Controllers    │  │  Auth Guards    │  │  Helpers        │ │
│  │  (*_action.php) │  │  (auth_guard)   │  │  (helpers.php)  │ │
│  └─────────────────┘  └─────────────────┘  └─────────────────┘ │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐ │
│  │  AJAX Handlers  │  │  Cron Jobs      │  │  Migrations     │ │
│  │  (ajax/*.php)   │  │  (cron/*.php)   │  │  (migrations/)  │ │
│  └─────────────────┘  └─────────────────┘  └─────────────────┘ │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                        DATA LAYER                               │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐ │
│  │  MySQL/MariaDB  │  │  File Storage   │  │  Sessions       │ │
│  │  (farmasi_ems)  │  │  (storage/)     │  │  (PHP + DB)     │ │
│  └─────────────────┘  └─────────────────┘  └─────────────────┘ │
└─────────────────────────────────────────────────────────────────┘
```

### 3.2 Technology Stack

| Layer | Technology | Version | Purpose |
|-------|-----------|---------|---------|
| **Backend** | PHP | 8.x | Server-side logic |
| **Database** | MariaDB | 11.x | Primary data store |
| **CSS Framework** | Tailwind CSS | 3.4.17 | Utility-first styling |
| **JavaScript** | Alpine.js | 3.15.8 | Reactive components |
| **Charts** | Chart.js | 4.5.1 | Data visualization |
| **Tables** | DataTables.net | 2.3.7 | Advanced tables |
| **Icons** | Heroicons | SVG | Icon library |
| **Rich Text** | Quill.js | 1.3.6 (CDN) | HTML editor |
| **Image Processing** | PHP GD | Built-in | Image compression |
| **Push Notifications** | Minishlink/web-push | 10.x | Web push API |
| **Spreadsheet** | PhpSpreadsheet | Latest | Excel import/export |

### 3.3 Directory Structure

```
ems2/
├── actions/           # Action handlers (business logic)
├── ajax/              # AJAX endpoints
├── api/               # REST API endpoints
├── assets/            # Frontend assets
│   ├── design/        # Design system
│   │   ├── tailwind/  # Tailwind source & build
│   │   ├── tokens/    # Design tokens
│   │   └── components/# Reusable components
│   └── vendor/        # Third-party libraries
├── auth/              # Authentication & authorization
├── config/            # Configuration files
├── cron/              # Scheduled jobs
├── dashboard/         # Main application pages
├── docs/              # Documentation
├── helpers/           # Helper utilities
├── migrations/        # Database migrations
├── partials/          # Reusable UI components
├── public/            # Public assets
├── storage/           # File uploads
│   ├── applicants/    # Job applicant files
│   ├── identity/      # Identity documents
│   ├── medical_records/ # Patient records
│   ├── reimbursements/  # Reimbursement docs
│   └── user_docs/     # User documents
└── vendor/            # Composer dependencies
```

---

## 4. Functional Specifications

### 4.1 Authentication & Authorization

#### FR-AUTH-001: Login System
| Attribute | Value |
|-----------|-------|
| **ID** | FR-AUTH-001 |
| **Name** | User Login |
| **Description** | Secure authentication with full name and 4-digit PIN |
| **Input** | Full name, PIN (4 digits) |
| **Validation** | Name must exist, PIN must match |
| **Output** | Session token, redirect to dashboard |
| **Security** | Password hashing, session management, device tracking |

#### FR-AUTH-002: Remember Me
| Attribute | Value |
|-----------|-------|
| **ID** | FR-AUTH-002 |
| **Name** | Persistent Login |
| **Description** | Extended session via secure cookie |
| **Mechanism** | Token-based remember me with expiration |
| **Storage** | `remember_tokens` table |
| **Security** | Token hashing, expiration validation |

#### FR-AUTH-003: Role-Based Access Control
| Attribute | Value |
|-----------|-------|
| **ID** | FR-AUTH-003 |
| **Name** | RBAC |
| **Description** | Menu and feature access based on role |
| **Roles** | admin, manager, staff, trainee |
| **Positions** | specialist, general_practitioner, co_asst, paramedic, etc. |
| **Enforcement** | Server-side guard + UI filtering |

### 4.2 Medical Records (Rekam Medis)

#### FR-MED-001: Patient Registration
| Attribute | Value |
|-----------|-------|
| **ID** | FR-MED-001 |
| **Name** | Patient Data Input |
| **Description** | Register new patient with demographic data |
| **Fields** | Name, Occupation, DOB, Phone, Gender, Address, Status |
| **Validation** | Required fields, format validation |
| **Default Values** | Occupation: Civilian, Address: INDONESIA |

#### FR-MED-002: Document Upload
| Attribute | Value |
|-----------|-------|
| **ID** | FR-MED-002 |
| **Name** | KTP & MRI Upload |
| **Description** | Upload patient ID and medical images |
| **KTP** | Required, JPG/PNG, max 5MB, auto-compress to 300KB |
| **MRI** | Optional, JPG/PNG, max 5MB, auto-compress to 500KB |
| **Storage** | `storage/medical_records/ktp/`, `storage/medical_records/mri/` |
| **Features** | Preview, compression, duplicate handling |

#### FR-MED-003: Medical Report Editor
| Attribute | Value |
|-----------|-------|
| **ID** | FR-MED-003 |
| **Name** | HTML Rich-Text Editor |
| **Description** | WYSIWYG editor for medical examination results |
| **Features** | Headings, formatting, lists, alignment, colors, links |
| **Output** | Sanitized HTML stored in database |
| **Library** | Quill.js (CDN) |

#### FR-MED-004: Medical Team Assignment
| Attribute | Value |
|-----------|-------|
| **ID** | FR-MED-004 |
| **Name** | Doctor & Assistant Selection |
| **Description** | Assign treating doctor and assistant |
| **Doctor Filter** | Minimum position: Co.Ast (co_asst, general_practitioner, specialist) |
| **Assistant Filter** | Minimum position: Paramedic (paramedic, co_asst, general_practitioner, specialist) |
| **Source** | `user_rh` table |

#### FR-MED-005: Operation Classification
| Attribute | Value |
|-----------|-------|
| **ID** | FR-MED-005 |
| **Name** | Operation Type |
| **Description** | Classify operation as major or minor |
| **Options** | Major (high risk), Minor (low risk) |
| **Default** | Minor |

### 4.3 Pharmacy Management

#### FR-PHR-001: Sales Recording
| Attribute | Value |
|-----------|-------|
| **ID** | FR-PHR-001 |
| **Name** | Pharmacy Sales |
| **Description** | Record medicine and package sales |
| **Data** | Consumer name, medicines, quantities, prices, packages |
| **Analytics** | Total items, revenue, bonus calculation |

#### FR-PHR-002: Inventory Tracking
| Attribute | Value |
|-----------|-------|
| **ID** | FR-PHR-002 |
| **Name** | Medicine Inventory |
| **Description** | Track medicine stock levels |
| **Categories** | Bandage, Painkiller, IFaks, Gauze, Iodine, Syringe |
| **Alerts** | Low stock warnings |

### 4.4 Human Resources

#### FR-HR-001: Leave Application (Cuti)
| Attribute | Value |
|-----------|-------|
| **ID** | FR-HR-001 |
| **Name** | Leave Request |
| **Description** | Submit leave application with date range |
| **Calculation** | Auto-calculate total days, remaining balance |
| **Workflow** | Submit → Validate → Approve/Reject → Update status |
| **Output** | Official leave letter (surat cuti) |

#### FR-HR-002: Resignation Application
| Attribute | Value |
|-----------|-------|
| **ID** | FR-HR-002 |
| **Name** | Resignation Request |
| **Description** | Submit resignation with notice period |
| **Workflow** | Submit → Review → Approve → Exit process |
| **Output** | Official resignation letter |

#### FR-HR-003: Position Promotion
| Attribute | Value |
|-----------|-------|
| **ID** | FR-HR-003 |
| **Name** | Position Promotion |
| **Description** | Apply for position promotion with requirements |
| **Requirements** | Defined per target position |
| **Workflow** | Submit → Review → Interview → Decision |

#### FR-HR-004: Attendance Tracking
| Attribute | Value |
|-----------|-------|
| **ID** | FR-HR-004 |
| **Name** | Web Attendance |
| **Description** | Clock in/out via web interface |
| **Data** | Timestamp, location (optional), notes |

### 4.5 Finance

#### FR-FIN-001: Reimbursement
| Attribute | Value |
|-----------|-------|
| **ID** | FR-FIN-001 |
| **Name** | Medical Reimbursement |
| **Description** | Claim medical expense reimbursement |
| **Documents** | Receipts, medical certificates |
| **Workflow** | Submit → Validate → Approve → Pay |

#### FR-FIN-002: Salary Management
| Attribute | Value |
|-----------|-------|
| **ID** | FR-FIN-002 |
| **Name** | Salary Processing |
| **Description** | Calculate and process monthly salary |
| **Components** | Base salary, bonus (40%), company profit (60%) |

### 4.6 Administration

#### FR-ADM-001: Event Management
| Attribute | Value |
|-----------|-------|
| **ID** | FR-ADM-001 |
| **Name** | Event Management |
| **Description** | Create and manage hospital events |
| **Features** | Event creation, participant registration, decision tracking |

#### FR-ADM-002: Document Generation
| Attribute | Value |
|-----------|-------|
| **ID** | FR-ADM-002 |
| **Name** | Letter & Minutes |
| **Description** | Generate official documents |
| **Templates** | Leave letters, resignation letters, meeting minutes |
| **Format** | HTML/PDF export |

---

## 5. Technical Specifications

### 5.1 Backend Architecture

#### Controller Pattern
```php
// Standard controller structure
<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

// Method validation
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid method');
}

// CSRF validation
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

// Business logic...
```

#### Helper Functions (config/helpers.php)
| Function | Purpose |
|----------|---------|
| `csrf_field()` | Generate CSRF token hidden input |
| `validateCsrfToken($token)` | Validate CSRF token |
| `compressImageSmart()` | Smart image compression |
| `uploadAndCompressFile()` | Upload and compress file |
| `hitung_sisa_cuti()` | Calculate remaining leave days |
| `generate_request_code()` | Generate unique request code |
| `is_user_on_cuti()` | Check if user is on leave |
| `format_tanggal_surat()` | Format date for letters |
| `format_surat_cuti()` | Format leave letter |
| `format_surat_resign()` | Format resignation letter |
| `can_approve_cuti_resign()` | Check approval permission |
| `get_status_badge()` | Get status badge HTML |
| `ems_normalize_position()` | Normalize position string |
| `ems_icon()` | Render Heroicon SVG |

### 5.2 Frontend Architecture

#### Component Library
```html
<!-- Card Component -->
<div class="card card-section">
    <div class="card-header">Title</div>
    <div class="card-body">Content</div>
</div>

<!-- Form Input -->
<div class="form-group">
    <label class="form-label">Label</label>
    <input type="text" class="form-input" />
</div>

<!-- Button -->
<button class="btn-primary">Primary</button>
<button class="btn-secondary">Secondary</button>
<button class="btn-warning">Warning</button>

<!-- Alert -->
<div class="alert alert-success">Success message</div>
<div class="alert alert-error">Error message</div>

<!-- Modal -->
<div class="modal-overlay">
    <div class="modal-box modal-shell">
        <div class="modal-head">...</div>
        <div class="modal-content">...</div>
        <div class="modal-foot">...</div>
    </div>
</div>
```

#### Alpine.js Components
```javascript
// File upload preview
function medicalForm() {
    return {
        ktpPreview: null,
        mriPreview: null,
        handleKtpUpload(event) {
            const file = event.target.files[0];
            if (file) {
                this.ktpPreview = URL.createObjectURL(file);
            }
        },
        handleMriUpload(event) {
            const file = event.target.files[0];
            if (file) {
                this.mriPreview = URL.createObjectURL(file);
            }
        }
    }
}
```

### 5.3 File Upload System

#### Image Compression Algorithm
```php
function compressImageSmart(
    string $sourcePath,
    string $targetPath,
    int $maxWidth = 1200,
    int $targetSize = 300000,
    int $minQuality = 70
): bool {
    // Get original dimensions
    $info = getimagesize($sourcePath);
    $width = $info[0];
    $height = $info[1];
    $mime = $info['mime'];
    
    // Resize if needed
    if ($width > $maxWidth) {
        $ratio = $maxWidth / $width;
        $newWidth = $maxWidth;
        $newHeight = (int)($height * $ratio);
    }
    
    // Progressive compression
    $quality = 90;
    do {
        compress($sourcePath, $targetPath, $quality);
        $quality -= 5;
    } while (filesize($targetPath) > $targetSize && $quality >= $minQuality);
    
    return true;
}
```

### 5.4 Session Management

#### Session Structure
```php
$_SESSION['user_rh'] = [
    'id' => (int),
    'name' => (string) full_name,
    'role' => (string) role,
    'position' => (string) normalized_position
];
```

#### Remember Me Token
```php
// Cookie format: user_id:random_token
$_COOKIE['remember_login'] = "123:abc123xyz";

// Storage in database
remember_tokens: {
    user_id: int,
    token_hash: varchar(255),
    created_at: datetime,
    expired_at: datetime
}
```

---

## 6. Database Schema

### 6.1 Core Tables

#### user_rh (User Registry)
```sql
CREATE TABLE `user_rh` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `role` varchar(50) NOT NULL,
  `position` varchar(100) NOT NULL,
  `pin_hash` varchar(255) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `cuti_start_date` date DEFAULT NULL,
  `cuti_end_date` date DEFAULT NULL,
  `cuti_days_total` int(11) DEFAULT 0,
  `cuti_status` varchar(50) DEFAULT NULL,
  `cuti_approved_by` int(11) DEFAULT NULL,
  `cuti_approved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_role` (`role`),
  KEY `idx_position` (`position`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### medical_records
```sql
CREATE TABLE `medical_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_name` varchar(100) NOT NULL,
  `patient_occupation` varchar(50) DEFAULT 'Civilian',
  `patient_dob` date NOT NULL,
  `patient_phone` varchar(20) DEFAULT NULL,
  `patient_gender` enum('Laki-laki','Perempuan') NOT NULL,
  `patient_address` varchar(255) DEFAULT 'INDONESIA',
  `patient_status` varchar(50) DEFAULT NULL,
  `ktp_file_path` varchar(255) NOT NULL,
  `mri_file_path` varchar(255) DEFAULT NULL,
  `medical_result_html` text NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `assistant_id` int(11) DEFAULT NULL,
  `operasi_type` enum('major','minor') NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_patient_name` (`patient_name`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_doctor_id` (`doctor_id`),
  KEY `idx_assistant_id` (`assistant_id`),
  CONSTRAINT `fk_mr_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `user_rh` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_mr_assistant` FOREIGN KEY (`assistant_id`) REFERENCES `user_rh` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_mr_created_by` FOREIGN KEY (`created_by`) REFERENCES `user_rh` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### cuti_requests
```sql
CREATE TABLE `cuti_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_code` varchar(50) NOT NULL,
  `user_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `total_days` int(11) NOT NULL,
  `reason_ic` text NOT NULL,
  `reason_ooc` text,
  `status` varchar(50) DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_cuti_user` FOREIGN KEY (`user_id`) REFERENCES `user_rh` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### resign_requests
```sql
CREATE TABLE `resign_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_code` varchar(50) NOT NULL,
  `user_id` int(11) NOT NULL,
  `resign_date` date NOT NULL,
  `reason` text NOT NULL,
  `status` varchar(50) DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_resign_user` FOREIGN KEY (`user_id`) REFERENCES `user_rh` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### sales (Pharmacy)
```sql
CREATE TABLE `sales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `medic_name` varchar(100) NOT NULL,
  `consumer_name` varchar(100) NOT NULL,
  `package_name` varchar(50) DEFAULT NULL,
  `qty_bandage` int(11) DEFAULT 0,
  `qty_ifaks` int(11) DEFAULT 0,
  `qty_painkiller` int(11) DEFAULT 0,
  `price` decimal(10,2) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_medic_name` (`medic_name`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### remember_tokens
```sql
CREATE TABLE `remember_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `expired_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`) REFERENCES `user_rh` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 6.2 Entity Relationship Diagram

```
┌─────────────────┐       ┌─────────────────┐
│    user_rh      │       │   sales         │
│─────────────────│       │─────────────────│
│ id (PK)         │       │ id (PK)         │
│ full_name       │       │ medic_name      │
│ role            │       │ consumer_name   │
│ position        │       │ package_name    │
│ pin_hash        │       │ qty_*           │
│ is_active       │       │ price           │
│ cuti_*          │       │ created_at      │
└────────┬────────┘       └─────────────────┘
         │
         │ 1:N
         ├──────────────────────────────────────┐
         │                                      │
         ▼                                      ▼
┌─────────────────┐                    ┌─────────────────┐
│ medical_records │                    │  cuti_requests  │
│─────────────────│                    │─────────────────│
│ id (PK)         │                    │ id (PK)         │
│ patient_*       │                    │ request_code    │
│ ktp_file_path   │                    │ user_id (FK)    │
│ mri_file_path   │                    │ start_date      │
│ medical_result  │                    │ end_date        │
│ doctor_id (FK)  │                    │ status          │
│ assistant_id(FK)│                    │ approved_*      │
│ created_by (FK) │                    └─────────────────┘
└─────────────────┘
```

---

## 7. API & Integrations

### 7.1 Internal AJAX Endpoints

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/ajax/get_medical_records.php` | GET | Fetch medical records for DataTable |
| `/ajax/load_doctors.php` | GET | Load doctor dropdown options |
| `/ajax/load_assistants.php` | GET | Load assistant dropdown options |

### 7.2 Push Notifications

```php
// Using Minishlink/web-push
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

$webPush = new WebPush([
    'VAPID' => [
        'subject' => 'mailto:admin@roxwoodhospital.com',
        'publicKey' => '...',
        'privateKey' => '...'
    ]
]);

$subscription = Subscription::create([
    'endpoint' => '...',
    'keys' => [...]
]);

$webPush->sendOneNotification($subscription, json_encode([
    'title' => 'New Notification',
    'body' => 'You have a new request to review'
]));
```

### 7.3 Spreadsheet Integration

```php
// Using PhpSpreadsheet for Excel import/export
use PhpOffice\PhpSpreadsheet\IOFactory;

// Import
$spreadsheet = IOFactory::load('data.xlsx');
$worksheet = $spreadsheet->getActiveSheet();
$data = $worksheet->toArray();

// Export
$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save('export.xlsx');
```

---

## 8. Security Specifications

### 8.1 Authentication Security

| Measure | Implementation |
|---------|----------------|
| **Password Hashing** | PHP `password_hash()` with bcrypt |
| **Session Management** | PHP sessions with secure cookies |
| **CSRF Protection** | Token-based validation on all forms |
| **Device Tracking** | Remember me token with device fingerprint |
| **Session Timeout** | Automatic logout after inactivity |

### 8.2 Input Validation

```php
// Required field validation
if (empty($patientName)) {
    throw new Exception('Nama pasien wajib diisi.');
}

// File type validation
$allowedTypes = ['image/jpeg', 'image/png'];
$info = getimagesize($file['tmp_name']);
if (!$info || !in_array($info['mime'], $allowedTypes, true)) {
    throw new Exception('File harus berformat JPG/PNG.');
}

// File size validation
if ($file['size'] > 5000000) {
    throw new Exception('Ukuran file maksimal 5MB.');
}
```

### 8.3 XSS Prevention

```php
// Output escaping
<?= htmlspecialchars($variable, ENT_QUOTES, 'UTF-8') ?>

// HTML sanitization (for rich text)
$medicalResultHtml = preg_replace(
    '/<script\b[^>]*>(.*?)<\/script>/is',
    '',
    $medicalResultHtml
);
```

### 8.4 SQL Injection Prevention

```php
// Prepared statements (PDO)
$stmt = $pdo->prepare("
    SELECT * FROM user_rh 
    WHERE id = :id AND is_active = 1
");
$stmt->execute([':id' => $userId]);
```

### 8.5 File Upload Security

| Check | Implementation |
|-------|----------------|
| **File Type** | MIME type validation + `getimagesize()` |
| **File Size** | Hard limit enforcement (5MB) |
| **Filename** | Sanitized with `uniqid()` + timestamp |
| **Storage** | Outside webroot or protected directory |
| **Access Control** | Session-based access validation |

---

## 9. UI/UX Design System

### 9.1 Design Tokens

#### Colors
| Token | Value | Usage |
|-------|-------|-------|
| `primary` | `#0ea5e9` | Primary actions, links |
| `primary-dark` | `#0284c7` | Hover states |
| `secondary` | `#0369a1` | Secondary actions |
| `success` | `#10b981` | Success messages |
| `warning` | `#f59e0b` | Warning messages |
| `danger` | `#ef4444` | Error messages |
| `surface` | `#ffffff` | Card backgrounds |
| `background` | `#f4f9fc` | Page background |
| `text` | `#0f172a` | Primary text |
| `muted` | `#64748b` | Secondary text |
| `border` | `#cbd5e1` | Borders |

#### Typography
| Size | Value | Usage |
|------|-------|-------|
| `11` | 11px | Small labels |
| `12` | 12px | Captions |
| `13` | 13px | Secondary text |
| `14` | 14px | Body text |
| `15` | 15px | Form labels |
| `16` | 16px | Inputs |
| `18` | 18px | Subheadings |
| `20` | 20px | Headings |
| `26` | 26px | Page titles |

#### Spacing
| Value | Size | Usage |
|-------|------|-------|
| `6` | 6px | Tight spacing |
| `8` | 8px | Default gap |
| `10` | 10px | Component padding |
| `12` | 12px | Form groups |
| `16` | 16px | Card padding |
| `24` | 24px | Section spacing |

#### Border Radius
| Value | Size | Usage |
|-------|------|-------|
| `6` | 6px | Buttons |
| `8` | 8px | Inputs |
| `12` | 12px | Cards |
| `16` | 16px | Modals |
| `999` | 999px | Pills, avatars |

### 9.2 Component Library

#### Buttons
```html
<button class="btn-primary">Primary</button>
<button class="btn-secondary">Secondary</button>
<button class="btn-warning">Warning</button>
<button class="btn-danger">Danger</button>
<button class="btn-sm">Small</button>
```

#### Forms
```html
<div class="form-group">
    <label class="form-label">Label</label>
    <input type="text" class="form-input" />
    <span class="form-hint">Helper text</span>
</div>
```

#### Cards
```html
<div class="card card-section">
    <div class="card-header">Title</div>
    <div class="card-body">Content</div>
</div>
```

#### Alerts
```html
<div class="alert alert-success">Success</div>
<div class="alert alert-error">Error</div>
<div class="alert alert-warning">Warning</div>
<div class="alert alert-info">Info</div>
```

### 9.3 Responsive Breakpoints

| Breakpoint | Width | Target |
|------------|-------|--------|
| `sm` | 640px | Mobile landscape |
| `md` | 768px | Tablets |
| `lg` | 1024px | Desktops |
| `xl` | 1280px | Large screens |
| `2xl` | 1536px | Extra large |

### 9.4 Icon System

```php
// Using Heroicons via helper
<?= ems_icon('home', 'h-5 w-5') ?>
<?= ems_icon('user-group', 'h-6 w-6 text-primary') ?>
<?= ems_icon('check-circle', 'h-5 w-5 text-success') ?>
```

---

## 10. Performance Requirements

### 10.1 Response Time Targets

| Metric | Target | Measurement |
|--------|--------|-------------|
| **Page Load** | < 2s | Time to interactive |
| **API Response** | < 500ms | 95th percentile |
| **File Upload** | < 3s | With compression |
| **Database Query** | < 100ms | Average query |
| **Search** | < 1s | Full-text search |

### 10.2 Concurrency

| Metric | Target |
|--------|--------|
| **Concurrent Users** | 50+ |
| **Requests/Second** | 100+ |
| **Database Connections** | 20 max |

### 10.3 Optimization Strategies

| Strategy | Implementation |
|----------|----------------|
| **Image Compression** | Auto-compress uploads to target size |
| **Lazy Loading** | Defer non-critical resources |
| **Caching** | Session caching, query result caching |
| **Indexing** | Database indexes on frequently queried columns |
| **CDN** | Quill.js loaded from CDN |
| **Minification** | Tailwind CSS minified build |

---

## 11. Deployment & Infrastructure

### 11.1 Server Requirements

| Component | Requirement |
|-----------|-------------|
| **PHP** | 8.0+ |
| **Database** | MariaDB 11.x / MySQL 8.x |
| **Web Server** | Apache 2.4+ / Nginx 1.20+ |
| **Extensions** | PDO, GD, mbstring, curl, json |
| **Storage** | 10GB+ (for file uploads) |
| **Memory** | 512MB+ PHP memory_limit |

### 11.2 Directory Permissions

```bash
# Storage directories (writable by web server)
chmod 755 storage/
chmod 755 storage/medical_records/
chmod 755 storage/reimbursements/
chmod 755 storage/user_docs/

# Config files (read-only)
chmod 644 config/database.php
chmod 644 config/helpers.php
```

### 11.3 Environment Configuration

```php
// config/database.php
$DB_HOST = 'localhost';
$DB_NAME = 'farmasi_ems';
$DB_USER = 'root';
$DB_PASS = env('DB_PASSWORD');

// Timezone
date_default_timezone_set('Asia/Jakarta');
```

### 11.4 Cron Jobs

```bash
# Daily: Update leave status
0 0 * * * php /path/to/ems2/cron/update_cuti_status.php

# Weekly: Cleanup expired sessions
0 0 * * 0 php /path/to/ems2/cron/cleanup_sessions.php
```

### 11.5 Backup Strategy

| Type | Frequency | Retention |
|------|-----------|-----------|
| **Database** | Daily | 30 days |
| **File Storage** | Weekly | 90 days |
| **Code** | On deploy | Always (git) |

---

## 12. Roadmap & Future Enhancements

### 12.1 Phase 1: Completed ✅

| Feature | Status | Date |
|---------|--------|------|
| Authentication System | ✅ Live | Jan 2026 |
| Medical Records | ✅ Live | Mar 2026 |
| Leave & Resignation | ✅ Live | Mar 2026 |
| Pharmacy Recap | ✅ Live | Feb 2026 |
| Reimbursement | ✅ Live | Feb 2026 |

### 12.2 Phase 2: In Progress 🚧

| Feature | Status | ETA |
|---------|--------|-----|
| Medical Records List View | 🚧 In Dev | Mar 2026 |
| Medical Records Edit/Delete | 🚧 In Dev | Mar 2026 |
| Export to PDF | 📋 Planned | Apr 2026 |
| Advanced Search & Filters | 📋 Planned | Apr 2026 |

### 12.3 Phase 3: Planned 📋

| Feature | Priority | ETA |
|---------|----------|-----|
| Mobile App (React Native) | High | Q3 2026 |
| API for Third-party Integration | High | Q3 2026 |
| Analytics Dashboard | Medium | Q3 2026 |
| Notification System | Medium | Q3 2026 |
| Multi-language Support | Low | Q4 2026 |

### 12.4 Future Considerations

| Area | Consideration |
|------|---------------|
| **Security** | Implement HTMLPurifier for better XSS protection |
| **Performance** | Add Redis caching layer |
| **Scalability** | Database read replicas for heavy queries |
| **Compliance** | HIPAA compliance for medical data |
| **Disaster Recovery** | Multi-region backup strategy |

---

## Appendix A: Glossary

| Term | Definition |
|------|------------|
| **DPJP** | Dokter Penanggung Jawab Pasien (Attending Physician) |
| **Cuti** | Leave (time off) |
| **Resign** | Resignation |
| **Rekam Medis** | Medical Records |
| **Farmasi** | Pharmacy |
| **Rekap** | Recap/Summary |
| **Reimbursement** | Medical expense claim |

---

## Appendix B: References

1. [PRD: Rekam Medis](prd-rekam-medis.md)
2. [UI Design System](ui-design-system.md)
3. [Implementation Plan: Cuti & Resign](progress_cuti_resign.md)
4. [Implementation Summary](../IMPLEMENTATION_SUMMARY.md)
5. [GitHub Repository](https://github.com/mryunkaka/ems2)

---

**Document Control**

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | Mar 8, 2026 | AI Assistant | Initial release |

---

*This document is confidential and intended for internal use only.*
*© 2026 Roxwood Hospital. All rights reserved.*
