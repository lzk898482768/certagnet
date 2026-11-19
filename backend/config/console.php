<?php
// +----------------------------------------------------------------------
// | 控制台配置（命令行指令）
// +----------------------------------------------------------------------
return [
    'commands' => [
        'cert:checkexpire' => 'app\command\CheckCertExpire',
        'gov:sync'         => 'app\command\GovSync',
        'redis:check'      => 'app\command\RedisCheck',
        // v2.99.48：存量附件迁移到 S3（默认干跑，--execute 正式执行）
        'attach:migrate-s3' => 'app\command\AttachMigrateS3',
        // v2.99.59：存量身份证号加密回填（幂等，可重复执行）
        'idcard:encrypt'    => 'app\command\IdCardEncrypt',
    ],
];
