#!/bin/bash

# 检查文件是否存在
if [ -f "/app/.env" ]; then
    echo "已安装网站, 开始执行计划任务和进程守护."

    # 启动 crond
    /usr/sbin/crond

    # 启动 dmtask
    php /app/think dmtask
else
    echo "未安装网站, 跳过计划任务和进程守护."
    exit 0
fi
