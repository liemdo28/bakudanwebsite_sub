-- CreateTable
CREATE TABLE `brands` (
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL,
    `logo_path` VARCHAR(500) NULL,
    `pla canteraary_color` VARCHAR(7) NOT NULL DEFAULT '#C8102E',
    `secondary_color` VARCHAR(7) NOT NULL DEFAULT '#1A1A1A',
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `brands_slug_key`(`slug`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `stores` (
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `brand_id` INTEGER NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL,
    `location_name` VARCHAR(200) NULL,
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `stores_slug_key`(`slug`),
    INDEX `stores_brand_id_idx`(`brand_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `link_pages` (
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `brand_id` INTEGER NOT NULL,
    `store_id` INTEGER NULL,
    `title` VARCHAR(200) NOT NULL,
    `slug` VARCHAR(200) NOT NULL,
    `headline` VARCHAR(300) NULL,
    `subheadline` VARCHAR(500) NULL,
    `hero_image_path` VARCHAR(500) NULL,
    `seo_title` VARCHAR(200) NULL,
    `seo_description` VARCHAR(500) NULL,
    `social_image_path` VARCHAR(500) NULL,
    `theme_json` JSON NULL,
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `published_at` DATETIME(3) NULL,
    `expires_at` DATETIME(3) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `link_pages_slug_key`(`slug`),
    INDEX `link_pages_brand_id_idx`(`brand_id`),
    INDEX `link_pages_store_id_idx`(`store_id`),
    INDEX `link_pages_slug_idx`(`slug`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `link_buttons` (
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `link_page_id` INTEGER NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `url` VARCHAR(2000) NOT NULL,
    `button_style` VARCHAR(50) NOT NULL DEFAULT 'pla canteraary',
    `sort_order` INTEGER NOT NULL DEFAULT 0,
    `icon_name` VARCHAR(100) NULL,
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `is_featured` BOOLEAN NOT NULL DEFAULT false,
    `animation` VARCHAR(50) NULL,
    `opens_in_new_tab` BOOLEAN NOT NULL DEFAULT true,
    `start_at` DATETIME(3) NULL,
    `end_at` DATETIME(3) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    INDEX `link_buttons_link_page_id_sort_order_idx`(`link_page_id`, `sort_order`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `link_click_events` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `link_page_id` INTEGER NOT NULL,
    `link_button_id` INTEGER NULL,
    `event_type` VARCHAR(20) NOT NULL,
    `ip_hash` VARCHAR(64) NULL,
    `user_agent` VARCHAR(500) NULL,
    `referer` VARCHAR(1000) NULL,
    `device_type` VARCHAR(20) NULL,
    `utm_source` VARCHAR(100) NULL,
    `utm_medium` VARCHAR(100) NULL,
    `utm_campaign` VARCHAR(100) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    INDEX `link_click_events_link_page_id_created_at_idx`(`link_page_id`, `created_at`),
    INDEX `link_click_events_link_button_id_idx`(`link_button_id`),
    INDEX `link_click_events_event_type_created_at_idx`(`event_type`, `created_at`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `qr_assets` (
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `link_page_id` INTEGER NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `format` VARCHAR(10) NOT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    INDEX `qr_assets_link_page_id_idx`(`link_page_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `users` (
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(200) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` VARCHAR(30) NOT NULL DEFAULT 'viewer',
    `brand_id` INTEGER NULL,
    `store_id` INTEGER NULL,
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `users_email_key`(`email`),
    INDEX `users_email_idx`(`email`),
    INDEX `users_brand_id_idx`(`brand_id`),
    INDEX `users_store_id_idx`(`store_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- AddForeignKey
ALTER TABLE `stores` ADD CONSTRAINT `stores_brand_id_fkey` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `link_pages` ADD CONSTRAINT `link_pages_brand_id_fkey` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `link_pages` ADD CONSTRAINT `link_pages_store_id_fkey` FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `link_buttons` ADD CONSTRAINT `link_buttons_link_page_id_fkey` FOREIGN KEY (`link_page_id`) REFERENCES `link_pages`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `link_click_events` ADD CONSTRAINT `link_click_events_link_page_id_fkey` FOREIGN KEY (`link_page_id`) REFERENCES `link_pages`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `link_click_events` ADD CONSTRAINT `link_click_events_link_button_id_fkey` FOREIGN KEY (`link_button_id`) REFERENCES `link_buttons`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `qr_assets` ADD CONSTRAINT `qr_assets_link_page_id_fkey` FOREIGN KEY (`link_page_id`) REFERENCES `link_pages`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `users` ADD CONSTRAINT `users_brand_id_fkey` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `users` ADD CONSTRAINT `users_store_id_fkey` FOREIGN KEY (`store_id`) REFERENCES `stores`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;
