<?php declare(strict_types=1);
namespace ZipImport;

return [
    'media_ingesters' => [
        'factories' => [
            'tempfile' => Service\MediaIngesterTempFileFactory::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view'
        ],
    ],
    'controllers' => [
        'factories' => [
            'ZipImport\Controller\Index' => Service\Controller\IndexControllerFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'zip-import' => [
                        'type' => 'Literal',
                        'options' => [
                            'route' => '/zip-import',
                            'defaults' => [
                                '__NAMESPACE__' => 'ZipImport\Controller',
                                'controller' => 'Index',
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'upload' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/upload',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'ZipImport\Controller',
                                        'controller' => 'Index',
                                        'action' => 'upload',
                                    ],
                                ],
                            ],
                            'mapping' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/mapping',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'ZipImport\Controller',
                                        'controller' => 'Index',
                                        'action' => 'mapping',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'Zip Import',
                'route' => 'admin/zip-import',
                'resource' => 'ZipImport\Controller\Index'
            ],
        ],
    ]
];