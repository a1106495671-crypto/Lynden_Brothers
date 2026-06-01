# Codex 工作流约定

本项目运行页面来自 Docker 容器 `geo-app`，访问地址通常是：

```text
http://localhost:18094/dl-console/
```

## 必须遵守

每次 Codex 修改代码时，必须同时保证：

1. 本地 git 工作区有改动，方便 VS Code Source Control 查看、commit、push。
2. Docker 容器里的 `/app` 同步同一份改动，方便浏览器页面立即生效。

不要只改 Docker，也不要只改本地。

## 推荐流程

1. 先修改本地项目文件。
2. 把同名文件同步到 Docker：

```bash
docker cp 本地路径 geo-app:/app/对应路径
```

例如：

```bash
docker cp admin/geo-monitor.php geo-app:/app/admin/geo-monitor.php
docker cp includes/example.php geo-app:/app/includes/example.php
```

3. 在 Docker 内验证 PHP 语法：

```bash
docker exec geo-app php -l /app/admin/geo-monitor.php
```

4. 如果页面没有刷新出效果，重启容器：

```bash
docker restart geo-app
```

5. 最后检查本地 git 状态：

```bash
git status --short
```

## 重要说明

`docker-compose.yml` 没有把整个项目目录挂载进 `geo-app`，所以本地代码改动不会自动反映到 `localhost:18094` 页面。必须手动 `docker cp` 到容器。

如果要推送代码，最终版本必须存在于本地 git 工作区；Docker 里的临时改动不能直接用于 git commit。
