CREATE TABLE `generated_resource` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `resource_id` INT DEFAULT NULL,
    `owner_id` INT DEFAULT NULL,
    `model` VARCHAR(190) DEFAULT '' NOT NULL,
    `responseid` VARCHAR(190) DEFAULT '' NOT NULL,
    `tokens_input` INT DEFAULT 0 NOT NULL,
    `tokens_output` INT DEFAULT 0 NOT NULL,
    `reviewed` TINYINT(1) DEFAULT 0 NOT NULL,
    `proposal` LONGTEXT NOT NULL COMMENT '(DC2Type:json)',
    `created` DATETIME NOT NULL,
    `modified` DATETIME DEFAULT NULL,
    INDEX IDX_FC30C3AF89329D25 (`resource_id`),
    INDEX IDX_FC30C3AF7E3C61F9 (`owner_id`),
    PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

ALTER TABLE `generated_resource` ADD CONSTRAINT FK_FC30C3AF89329D25 FOREIGN KEY (`resource_id`) REFERENCES `resource` (`id`) ON DELETE CASCADE;
ALTER TABLE `generated_resource` ADD CONSTRAINT FK_FC30C3AF7E3C61F9 FOREIGN KEY (`owner_id`) REFERENCES `user` (`id`) ON DELETE SET NULL;

