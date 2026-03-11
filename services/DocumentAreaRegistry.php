<?php

namespace Services;

class DocumentAreaRegistry
{
    private static $registry = [
        'archivio' => [
            'label' => 'Archivio',
            'ui_host' => 'root',
            'permissions' => [
                'view' => 'view_archivio',
                'manage' => 'manage_archivio'
            ],
            'nextcloud_root' => 'ARCHIVIO',
            'macro_policy' => 'multiple',
            'color' => '#4CAF50',
            'upload_dir' => 'uploads/archivio',
        ],
        'qualita' => [
            'label' => 'Qualità',
            'ui_host' => 'root',
            'permissions' => [
                'view' => 'view_qualita',
                'manage' => 'manage_qualita'
            ],
            'nextcloud_root' => 'QUALITA',
            'macro_policy' => 'multiple',
            'color' => '#ce221c',
            'upload_dir' => 'uploads/qualita',
        ],
        'formazione' => [
            'label' => 'Formazione',
            'ui_host' => 'hr',
            'permissions' => [
                'view' => 'view_formazione',
                'manage' => 'manage_formazione'
            ],
            'nextcloud_root' => 'FORMAZIONE',
            'macro_policy' => 'single',
            'color' => '#f39c12',
            'upload_dir' => 'uploads/formazione',
        ]
    ];

    public static function getRegistry(): array
    {
        return self::$registry;
    }

    public static function getDocumentAreaConfig(string $documentArea): ?array
    {
        return self::$registry[$documentArea] ?? null;
    }

    public static function isValid(string $documentArea): bool
    {
        return isset(self::$registry[$documentArea]);
    }
}
