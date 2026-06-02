# Docker 同步强制提醒

这个项目的浏览器页面运行在 Docker 容器 `geo-app`，不是直接读取本地工作区。

每次修改本地代码后，必须同步到容器：

```bash
docker cp 本地路径 geo-app:/app/对应路径
docker exec geo-app php -l /app/对应路径
```

例如修改 AI 模型页面后：

```bash
docker cp admin/ai-models.php geo-app:/app/admin/ai-models.php
docker exec geo-app php -l /app/admin/ai-models.php
```

访问 `http://localhost:18094/dl-console/` 没变化时，优先检查是否忘了 `docker cp`。

不要只改本地文件。不要只改 Docker。最终代码必须同时存在于本地 git 工作区和 Docker 容器里。
