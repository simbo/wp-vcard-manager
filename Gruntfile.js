module.exports = function(grunt) {
    'use strict';

    // Load all grunt tasks matching the `grunt-*` pattern
    require('load-grunt-tasks')(grunt);

    // Force use of Unix newlines
    grunt.util.linefeed = '\n';

    var pkg = grunt.file.readJSON('package.json');

    /* =============================================================================
       Project Configuration
       ========================================================================== */

    grunt.initConfig({

        /* =============================================================================
           Get NPM data
           ========================================================================== */

        pkg: pkg,

        /* =============================================================================
           Task config: Clean
           ========================================================================== */

        clean: {
            vendor: [
                'vendor/*'
            ],
            css: [
                'assets/css/*'
            ],
            js: [
                'assets/js/*',
            ]
        },

        /* =============================================================================
           Task Config: Copy dependency files
           ========================================================================== */

        copy: {

            // custom metaboxes
            dep_metaboxes: {
                expand: true,
                cwd: 'bower_components/Custom-Metaboxes-and-Fields-for-WordPress/',
                src: [
                    'init.php',
                    '*.min.css',
                    '*/*'
                ],
                dest: 'vendor/metabox/'
            },

            // plugin update checker
            dep_updatechecker: {
                expand: true,
                cwd: 'bower_components/plugin-update-checker/',
                src: [
                    '**/*'
                ],
                dest: 'vendor/plugin-update-checker/'
            },

            // phpqrcode
            dep_qrcode: {
                expand: true,
                cwd: 'bower_components/phpqrcode/',
                src: [
                    '**',
                    '*.php'
                ],
                dest: 'vendor/qrcode/'
            },

            // plugin comment update
            pkg_plugin: {
                src: pkg.name+'.php',
                dest: pkg.name+'.php',
                options: {
                    process: function( content, srcpath ) {
                        return content.replace(
                            /(\n\s*version:\s+)[0-9\.]+/i,
                            '$1'+pkg.version
                        );
                    }
                }
            },

        },

        /* =============================================================================
           Task config: Update JSON
           ========================================================================== */

        update_json: {
            pkg_bower: {
                src: 'package.json',
                dest: 'bower.json',
                fields: [
                    'version'
                ]
            },
            pkg_wpplugin: {
                src: 'package.json',
                dest: 'wp-plugin.json',
                fields: [
                    'version'
                ]
            }
        },

        /* =============================================================================
           Task Config: LESS
           ========================================================================== */

        less: {
            options: {
                strictMath: true,
                sourceMap: true,
                strictImports: true,
                outputSourceFiles: true,
                report: 'min',
                compress: true
            },
            styles: {
                options: {
                    sourceMapURL: 'styles.min.css.map',
                    sourceMapFilename: 'assets/css/styles.min.css.map'
                },
                files: {
                    'assets/css/styles.min.css': 'assets/less/styles.less'
                }
            }
        },

        /* =============================================================================
           Task config: Autoprefixer
           ========================================================================== */

        autoprefixer: {
            options: {
                browsers: [
                    'last 2 versions',
                    'ie 9',
                    'android 2.3',
                    'android 4',
                    'opera 12'
                ],
                map: true
            },
            theme: {
                src: 'assets/css/styles.min.css'
            }
        },

        /* =============================================================================
           Task Config: CSSLint
           ========================================================================== */

        csslint: {
            options: {
                'adjoining-classes': false,
                'unique-headings': false,
                'important': false,
                'unqualified-attributes': false,
                'outline-none': false,
                'box-sizing': false,
                'compatible-vendor-prefixes': false,
                'universal-selector': false,
                'regex-selectors': false,
                'zero-units': false,
                'box-model': false,
                'known-properties': false,
                'shorthand': false,
                'qualified-headings': false,
                'gradients': false,
                'font-sizes': false,
                'floats': false,
                'text-indent': false,
                'overqualified-elements': false,
                'ids': false,
                'duplicate-properties': false,
                'fallback-colors': false,
                'empty-rules': false,
                'vendor-prefix': false
            },
            src: [
                'assets/css/styles.min.css'
            ]
        },

        /* =============================================================================
           Task config: Coffeescript
           ========================================================================== */

        coffee: {
            options: {
                separator: '\n',
                bare: true,
                join: false,
                sourceMap: true
            },
            compile: {
                files: {
                    'assets/js/script.js': [
                        'assets/coffee/main.coffee'
                    ]
                }
            }
        },

        /* =============================================================================
           Task Config: JSHint
           ========================================================================== */

        jshint: {
            options: {
                'indent'   : 2,
                'quotmark' : 'single'
            },
            js: {
                src: 'assets/js/script.js'
            }
        },

        /* =============================================================================
           Task Config: Uglify
           ========================================================================== */

        uglify: {
            options: {
                sourceMap: true
            },
            js: {
                files: {
                    'assets/js/script.min.js': [
                        'assets/js/script.js'
                    ]
                },
            }
        },

        /* =============================================================================
           Task Config: Watch
           ========================================================================== */

        watch: {
            php: {
                files: [
                    '*.php',
                    'src/*.php'
                ],
                options: {
                    livereload: true
                }
            },
            pkg: {
                files: [
                    'package.json'
                ],
                tasks: [
                    'update-pkg',
                    'notify:pkg'
                ]
            },
            less: {
                files: [
                    'assets/less/*.less',
                    'assets/less/mixins/*.less'
                ],
                tasks: [
                    'build-css',
                    'notify:less'
                ],
                options: {
                    livereload: true
                }
            },
            coffee: {
                files: [
                    'assets/coffee/*.coffee'
                ],
                tasks: [
                    'build-js',
                    'notify:coffee'
                ],
                options: {
                    livereload: true
                }
            }
        },

        /* =============================================================================
           Task Config: Notifications
           ========================================================================== */

        notify: {
            pkg: {
                options: {
                    title: 'PKG Update',
                    message: 'Package files updated.'
                }
            },
            less: {
                options: {
                    title: 'LESS',
                    message: 'CSS generated, linted and minified.'
                }
            },
            coffee: {
                options: {
                    title: 'Coffeescript',
                    message: 'Javascript generated, linted and minified.'
                }
            }
        }

    });

    /* =============================================================================
       Custom Tasks
       ========================================================================== */

    grunt.registerTask( 'copy-deps', [
        'clean:vendor',
        'copy:dep_metaboxes',
        'copy:dep_updatechecker',
        'copy:dep_qrcode'
    ]);
    grunt.registerTask( 'update-pkg', [
        'copy:pkg_plugin',
        'update_json:pkg_bower',
        'update_json:pkg_wpplugin'
    ]);
    grunt.registerTask( 'build-css', [
        'clean:css',
        'less',
        'autoprefixer',
        'csslint'
    ]);
    grunt.registerTask( 'build-js', [
        'clean:js',
        'coffee',
        'jshint:js',
        'uglify'
    ]);
    grunt.registerTask( 'build', [
        'update-pkg',
        'copy-deps',
        'build-css',
        'build-js',
    ]);
    grunt.registerTask( 'default', [
        'build',
        'watch'
    ]);

};
