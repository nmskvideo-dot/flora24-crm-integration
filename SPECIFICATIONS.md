# Спецификация Flora24 CRM Integration

Технические характеристики и API спецификация плагина.

## 📌 Общая информация

| Свойство | Значение |
|----------|----------|
| **Название** | Flora24 CRM Integration for MFlowers |
| **Тип** | WordPress плагин |
| **Версия** | 1.4.2 |
| **PHP Версия** | 7.2+ |
| **WordPress** | 5.0+ |
| **WooCommerce** | 4.0+ |
| **Лицензия** | MIT |

## 🔌 API Интеграция

### Flora24 API Endpoint
```
Base URL: https://api.flora24.online/v1/
Timeout: 15-30 секунд
Method: GET, POST
Format: JSON
```

### Аутентификация
```
Header: X-API-Key: [YOUR_API_KEY]
Header: X-Client-Name: mflowers-wp-sync
Header: X-Client-Version: 1.4.2
Header: Accept: application/json
```

### API Запрос
```http
GET /v1/products HTTP/1.1
Host: api.flora24.online
X-API-Key: YOUR_API_KEY_HERE
X-Client-Name: mflowers-wp-sync
X-Client-Version: 1.4.2
Accept: application/json
```

### API Ответ
```json
{
  "products": [
    {
      "id": "PRD-ABC123",
      "name": "Букет Весна",
      "price": 28000,
      "active": true,
      "description": "Красивый весенний букет"
    },
    {
      "id": "PRD-XYZ789",
      "name": "Букет Осень",
      "price": 35000,
      "active": false,
      "description": "Осенний букет"
    }
  ]
}
```

## 💾 WordPress Options (Параметри БД)

| Option Key | Type | Default | Description |
|----------|------|---------|-------------|
| `mflowers_flora24_api_key` | string | '' | Flora24 API ключ |
| `mflowers_flora24_cron_enable` | string | 'no' | Включена ли автосинхронизация |
| `mflowers_flora24_cron_interval` | int | 3600 | Интервал в секундах |

## 📋 Post Meta (Метаданные товара)

| Meta Key | Type | Description |
|----------|------|-------------|
| `_flora24_id` | string | ID товара в Flora24 (PRD-xxx или ПРД-xxx) |
| `_flora24_sync_status` | string | Статус синхронизации: pending, synced, not_found |
| `_flora24_last_sync` | string | Дата последней синхронизации (timestamp) |

## 🔄 AJAX Endpoints

### mflowers_flora24_admin_scan
**Назначение:** Сканирование товаров с Flora24 ID

**Метод:** POST
**Параметры:** Нет

**Ответ (успех):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1234,
      "title": "Букет Весна",
      "flora_id": "PRD-ABC123",
      "price": "250",
      "stock": "<span style='color:#46b450;'>В наявності</span>",
      "valid": true,
      "sync_status": "synced",
      "edit_url": "https://example.com/wp-admin/post.php?post=1234&action=edit"
    }
  ]
}
```

### mflowers_flora24_execute_single_sync
**Назначение:** Синхронизация одного товара

**Метод:** POST
**Параметры:**
- `product_id` (int) — ID товара WooCommerce
- `flora_id` (string) — ID товара Flora24

**Ответ (успех):**
```json
{
  "success": true,
  "data": {
    "price": 280,
    "stock_html": "<span style='color:#46b450;'>В наявності</span>",
    "log_changes": "Ціна: [250 грн -> 280 грн] | Наявність: [В наявності -> Немає]",
    "title": "Букет Весна"
  }
}
```

### mflowers_flora24_read_log_file
**Назначение:** Чтение архивного лога

**Метод:** POST
**Параметры:**
- `filename` (string) — Имя файла лога (sync-YYYY-MM-DD.log)

**Ответ (успех):**
```json
{
  "success": true,
  "data": "[14:23:45] Запуск синхронізації...\n[14:23:50] Товар ID 1234 оновлено..."
}
```

## 🔐 Хуки WordPress

### Actions

```php
// Срабатывает при каждой автоматической синхронизации
do_action('mflowers_flora24_cron_sync_event');

// Срабатывает после синхронизации товара
do_action('mflowers_flora24_after_sync', $product_id, $flora_data, $old_price, $new_price);
```

### Filters

```php
// Фильтрует интервал синхронизации
$interval = apply_filters('mflowers_flora24_cron_interval', $interval);

// Фильтрует список товаров для синхронизации
$products = apply_filters('mflowers_flora24_products_to_sync', $products_query->posts);
```

## 📁 Структура файлов

```
flora24-crm-integration/
├── flora24-crm-integration.php    # Основной файл плагина
├── README.md                      # Документация
├── CHANGELOG.md                   # История изменений
├── LICENSE                        # MIT Лицензия
├── CONTRIBUTING.md                # Правила для разработчиков
├── CODE_OF_CONDUCT.md            # Кодекс поведения
├── SPECIFICATIONS.md              # Эта файл
└── composer.json                  # Composer конфиг
```

## 🗂️ Логи и файлы

### Местоположение логов
```
wp-content/uploads/flora24-logs/sync-YYYY-MM-DD.log
```

### Формат записи
```
[HH:MM:SS] [Тип] Сообщение
```

### Типы сообщений
- **Запуск** — начало операции
- **успішно** — успешное завершение
- **Помилка** — критическая ошибка
- **Запит** — запрос к API

## ⏱️ Временные интервалы (Cron)

| Значение (сек) | Название | Для кого |
|----------------|----------|----------|
| 900 | Кожні 15 хвилин | Очень активные магазины |
| 1800 | Кожні 30 хвилин | Высокая активность |
| 3600 | **Кожну годину** | ⭐ Рекомендуется |
| 7200 | Кожні 2 години | Средняя активность |
| 10800 | Кожні 3 години | Низкая активность |
| 21600 | Кожні 6 годин | Стабильный каталог |
| 43200 | Кожні 12 годин | Редкие изменения |
| 86400 | Раз на добу | Минимальная активность |

## 🔄 Жизненный цикл синхронизации

```
START
  │
  ├─ Получить API ключ из БД
  │
  ├─ Запросить список товаров из Flora24 API
  │
  ├─ Для каждого товара WooCommerce:
  │  │
  │  ├─ Найти соответствующий товар в ответе API
  │  │
  │  ├─ Если найден:
  │  │  ├─ Сравнить цену и доступность
  │  │  ├─ Если изменилось - обновить товар
  │  │  └─ Сохранить статус "synced"
  │  │
  │  └─ Если не найден:
  │     └─ Сохранить статус "not_found"
  │
  ├─ Записать результаты в лог
  │
  └─ END
```

## 🛡️ Безопасность

### Проверки
- ✅ `current_user_can('manage_woocommerce')` — проверка прав
- ✅ `wp_verify_nonce()` — проверка CSRF токена
- ✅ `sanitize_text_field()` — очистка входных данных
- ✅ `esc_attr()`, `esc_html()` — экранирование вывода

### Защита данных
- 🔐 API ключ хранится в WordPress options
- 🔐 Все запросы используют HTTPS
- 🔐 Логи содержат только суммарную информацию
- 🔐 Старые логи удаляются через 30 дней

## 📊 Производительность

### Оптимизации
- Пакетная обработка товаров (batch processing)
- Кеширование ответа API за время сеанса
- Пропуск товаров без изменений
- Использование WP_Query для эффективного запроса

### Лимиты
- Max timeout для API запроса: 30 секунд
- Max товаров на сканирование: -1 (без лимита)
- Max размер лога: не ограничен
- Удаление логов: старше 30 дней

## 🧪 Тестирование

### Требуемые тесты
- [ ] Успешная синхронизация одного товара
- [ ] Массовая синхронизация нескольких товаров
- [ ] Обработка ошибок API
- [ ] Обработка товаров без Flora24 ID
- [ ] Cron задачи выполняются по расписанию
- [ ] Логи записываются корректно
- [ ] Старые логи удаляются через 30 дней

## 📞 Контакты

- **GitHub:** https://github.com/nmskvideo-dot/flora24-crm-integration
- **Issues:** https://github.com/nmskvideo-dot/flora24-crm-integration/issues
- **Author:** Roman NMSK

---

**Последнее обновление:** 2024-2026
