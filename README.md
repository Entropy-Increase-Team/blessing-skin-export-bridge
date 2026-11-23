# blessing-skin-export-bridge

<p>
  <a href="https://github.com/Entropy-Increase-Team/blessing-skin-export-bridge/actions/workflows/ci.yml"><img src="https://img.shields.io/github/actions/workflow/status/Entropy-Increase-Team/blessing-skin-export-bridge/ci.yml?branch=main&style=flat-square&logo=githubactions&logoColor=white&label=CI" alt="CI"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/Entropy-Increase-Team/blessing-skin-export-bridge?style=flat-square" alt="License"></a>
  <img src="https://img.shields.io/badge/Blessing_Skin-%5E6.0-4F46E5?style=flat-square" alt="Blessing Skin ^6.0">
  <img src="https://img.shields.io/badge/PHP-%5E8.0-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP ^8.0">
  <img src="https://img.shields.io/badge/Bridge_Protocol-v1-0F766E?style=flat-square" alt="Bridge Protocol v1">
  <a href="https://github.com/Entropy-Increase-Team/blessing-skin-export-bridge/stargazers"><img src="https://img.shields.io/github/stars/Entropy-Increase-Team/blessing-skin-export-bridge?style=flat-square&logo=github" alt="GitHub Stars"></a>
</p>

**Blessing Skin Server v6.x 专用的只读迁移导出桥接插件**，配合 [SkinLoom（云织）](https://github.com/Entropy-Increase-Team/skinloom) 安装向导使用，无需开放 MySQL/PostgreSQL 数据库端口或手动拷贝海量材质归档，即可通过 HTTPS 安全、分批地将原有 Blessing Skin 站点的用户、角色、衣柜、站点设置及材质文件远程迁移到全新的 SkinLoom 实例。

> 本仓库作为 **独立插件仓库** 维护（不混入 SkinLoom Go/Next.js 主仓），遵循 Blessing Skin Server v6 官方插件规范（`^6.0.0`），源码目录下 `src/` 采用 PSR-4（`SkinLoomBridge\`）自动加载，无需运行 Composer 安装运行期依赖即可直接部署。

---

## 插件解决了什么问题

在传统迁移方式中，旧版 Blessing Skin 与新部署的 SkinLoom 不在同一台服务器（或者材质存储在阿里云 OSS / 腾讯云 COS / 七牛云等对象存储中）时，站长往往需要：
1. 将旧站数据库端口暴露到公网或搭设隧道；
2. 登录服务器打包导出并跨机拷贝动辄数 GB 的 `storage/textures` 文件；
3. 手工转换不同云存储插件中的文件。

启用本插件后，Blessing Skin 会暴露 3 个 **严格只读** 的受保护 HTTP 端点，由 SkinLoom 安装向导通过 HTTPS 并发拉取行数据与材质流，并在本地完成截断检测与 SHA-256 完整性校验。

---

## 安全与稳定性设计

- **超级管理员独占配置权**：后台配置面板与令牌签发仅限 `permission >= User::SUPER_ADMIN (2)` 访问，普通管理员无法生成导出凭证。
- **短时效、仅存哈希的 Bearer 令牌**：签发 256-bit 随机令牌（可配有效期 `1 / 6 / 24 / 72 / 168` 小时，默认 `24` 小时）；明文仅在生成当次展示一次，数据库 `options` 表仅保存 `hash('sha256', $token)` 与过期时间戳。
- **可选 IP / CIDR 白名单**：支持同时配置多条 IPv4 / IPv6 地址与 CIDR 网段（`IpMatcher`），仅放行来自 SkinLoom 服务器的拉取请求。
- **迁移窗口期写入冻结（Freeze Writes）**：通过全局中间件按需拦截前台注册、改密、角色编辑与材质上传删除等写操作，同时保持管理后台和 `/plugin/skinloom-bridge/*` 畅通；无需执行 `php artisan down`，且令牌过期或撤销后自动解冻。
- **防代理误缓存与敏感字段隔离**：所有桥接响应强制附加 `Cache-Control: private, no-store, max-age=0`、`X-Content-Type-Options: nosniff` 与 `X-SkinLoom-Bridge-Version: 1`；导出 `options` 时自动剔除 `skinloom_bridge_*` 插件自身键，并允许站长开关控制是否包含 `ygg_private_key` 与 OAuth Client Secret。
- **零内存膨胀与旧库容错**：材质文件经 `Storage::disk('textures')` 透明读取（兼容本地目录、OSS、COS、七牛云等驱动），`HEAD` 请求仅读取前 32 字节 PNG `IHDR` 解析宽高，`GET` 使用 `64 KiB` 分块流式响应；JSON 编码启用 `JSON_INVALID_UTF8_SUBSTITUTE`，避免老数据库残留非 UTF-8 字符引起 500 报错；使用键集游标分页与 `100001` 上限表计数防止大表超时。
- **禁用/卸载自动销毁凭证**：插件被禁用或删除时（`callbacks.php`），自动清空令牌哈希、过期时间与冻结开关。

---

## 站长安装与迁移流程

### 1. 安装并启用插件

1. 从本仓库 Release（或 CI 构建产物）下载 `blessing-skin-export-bridge.zip`，解压后使目录结构为 `<Blessing Skin 根目录>/plugins/blessing-skin-export-bridge/package.json`（或在 BS 插件管理页直接上传安装包）。
2. 进入 Blessing Skin **管理面板 → 插件管理**，启用 **SkinLoom 迁移桥接 (Export Bridge)**，点击 **配置**。

### 2. 配置安全策略并生成令牌

1. **配置选项**（右侧表单）：建议保持默认的 24 小时有效期；如 SkinLoom 服务器有固定公网 IP，建议填入 **IP / CIDR 白名单**；正式切换前勾选 **开启写入冻结**。
2. **生成令牌**（左侧卡片）：点击 **生成 256-bit 只读令牌**。页面将一次性显示：
   - **旧站地址（Bridge URL）** 与 **Bearer Token**
   - **一键粘贴连接串（JSON）**（含 `url` 与 `token`）
3. 在 SkinLoom 安装向导第 3B 步选择 **通过迁移桥接插件远程拉取**，直接把连接串粘贴进“旧站地址”输入框即可自动填充，之后运行测试与模拟试跑（Dry-Run）并执行正式迁移。

### 3. 迁移完成后清理

1. 确认 SkinLoom 迁移完成并通过 `skinloom import compare` 等检查。
2. 回到 Blessing Skin 插件配置页点击 **立即撤销令牌并解除冻结**，并在插件管理页 **禁用并删除** 本插件。

### 反向代理配置提示

- 公网环境 SkinLoom 强制使用 `https://`，仅私有局域网调试才可勾选可信内网 HTTP。
- 请确认 Nginx / Apache 未剥离 `Authorization` 请求头：
  - **Nginx**：`proxy_set_header Authorization $http_authorization;`
  - **Apache**：启用 `CGIPassAuth On`

---

## 开发者说明

### 目录结构

```text
blessing-skin-export-bridge/
├── .github/workflows/ci.yml           # PHP 8.2 语法检查、php-cs-fixer、单测与压缩包打包 CI
├── bootstrap.php                      # 注册插件路由与按需挂载 FreezeWrites 中间件
├── callbacks.php                      # 插件启用初始化默认值；禁用/删除自动吊销令牌并解冻
├── routes.php                         # 三个只读导出路由（绑定 AuthenticateBridge 中间件）
├── package.json                       # Blessing Skin v6 插件清单（namespace: SkinLoomBridge）
├── src/
│   ├── Configuration.php              # 超管专属配置控制器与 OptionForm/一次性令牌签发逻辑
│   ├── Controllers/
│   │   └── BridgeController.php       # manifest / rows / texture 只读导出实现
│   ├── Middleware/
│   │   ├── AuthenticateBridge.php     # Bearer SHA-256 常量时间鉴权、TTL 与 CIDR 白名单校验
│   │   └── FreezeWrites.php           # 迁移期前台写请求冻结（放行 admin 与 bridge 路由）
│   └── Support/
│       ├── IpMatcher.php              # IPv4/IPv6 与 CIDR 校验及匹配工具
│       └── TextureReader.php          # Storage::disk('textures') 抽象与 PNG IHDR 轻量探测
├── views/config.twig                  # AdminLTE 后台配置视图
├── lang/{zh_CN,en}/{config,general}.yml # 中英文多语言翻译
└── tests/SupportTest.php              # IpMatcher 与 TextureReader 单元测试
```

### 导出协议概要（Protocol v1）

所有接口由 `AuthenticateBridge` 保护，请求须携带 `Authorization: Bearer <token>`：

| 方法与路径 | 说明 |
|---|---|
| `GET /plugin/skinloom-bridge/manifest` | 返回协议版本 `protocolVersion: 1`、`capabilities`、BS 版本、表前缀、时区、材质驱动 `fsDriver`、密码算法（`PWD_METHOD`/`SALT`/`PASSWORD_METHODS`）、表行数、遗留表统计、扩展提示 `extensions` 及过滤后的 `options`。 |
| `GET /plugin/skinloom-bridge/rows?table=...&after=...&limit=500` | 白名单表（`users`、`players`、`textures`、`user_closet`、`reports`、`notifications`、`uuid`、`language_lines`、`oauth_clients`）键集游标分页读取（`limit` 范围 `1..1000`）。 |
| `GET \| HEAD /plugin/skinloom-bridge/textures/{hash}` | 校验 64 位小写 hex 哈希，流式输出材质文件并附带 `Content-Length`、`X-Texture-Width`、`X-Texture-Height` 与（本地盘可读时）`X-Texture-Sha256`。 |

> **v2 插件演进预留**：`manifest` 中的 `extensions` 目前会探测社区插件扩展表（如 `textures_description`、`invitation_codes`、`mojang_verifications`）行数；核心表暂保持白名单最小暴露面，后续配合 SkinLoom v2 迁移插件可扩展按需导出。

### 本地校验与打包

仓库不包含前端构建步骤与 Composer 运行期依赖；开发与 CI 规范对齐 `bs-community/blessing-skin-plugins`（`@Symfony` PHP CS 规则）：

```bash
# PHP 语法检查
find . -maxdepth 4 -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l

# 运行无依赖单元测试
php tests/SupportTest.php

# 安装开发依赖并执行代码风格检查
composer install --no-interaction
./vendor/bin/php-cs-fixer fix --dry-run --stop-on-violation --diff
```

CI（`.github/workflows/ci.yml`）会在推送后自动执行上述检查，并将插件运行所需文件打包为 `blessing-skin-export-bridge.zip`（剔除 `tests/`、`vendor/`、`composer.json` 等开发文件）。

---

## 开源许可

[MIT License](LICENSE)
