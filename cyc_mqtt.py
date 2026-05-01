import paho.mqtt.client as mqtt
import json
import requests

# --- MQTT 設定區 ---
MQTT_BROKER = "114.34.37.152"
MQTT_PORT = 1883
MQTT_USER = "admin"
MQTT_PW = "nrau-hp"
MQTT_TOPIC = "meter"  # 根據你的截圖，主題是 meter

# --- PHP API 設定區 ---
# 請更換為你放置 save_mqtt.php 的實際網址
PHP_API_URL = "http://localhost:9091/cyc013_powermeter/power_meter.php" 

# 當連線至 MQTT Broker 成功時觸發
def on_connect(client, userdata, flags, rc, properties=None):
    if rc == 0:
        print("✅ 已成功連線至 MQTT Broker")
        client.subscribe(MQTT_TOPIC)
        print(f"📡 正在監聽主題: {MQTT_TOPIC}...")
    else:
        print(f"❌ 連線失敗，錯誤碼: {rc}")

# 當接收到 MQTT 訊息時觸發
def on_message(client, userdata, msg):
    try:
        # 1. 解碼訊息內容
        payload_str = msg.payload.decode('utf-8')
        print(f"\n📩 收到原始訊息: {payload_str}")

        # 2. 將 JSON 字串轉為 Python 字典 (驗證格式是否正確)
        data_json = json.loads(payload_str)

        # 3. 透過 HTTP POST 發送給 PHP
        headers = {'Content-Type': 'application/json'}
        response = requests.post(PHP_API_URL, json=data_json, headers=headers, timeout=5)

        # 4. 檢查 PHP 回傳結果
        if response.status_code == 200:
            print(f"🚀 資料已轉發至 PHP: {response.text}")
        else:
            print(f"⚠️ PHP 回應錯誤: {response.status_code}")

    except json.JSONDecodeError:
        print("❌ 收到非 JSON 格式訊息，略過處理。")
    except requests.exceptions.RequestException as e:
        print(f"❌ 無法連線至 PHP API: {e}")
    except Exception as e:
        print(f"⚠️ 發生未知錯誤: {e}")

# --- 主程式 ---
# 初始化 MQTT Client (使用 2.x 版本 API)
client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)

# 設定帳號密碼
client.username_pw_set(MQTT_USER, MQTT_PW)

# 指定回呼函數
client.on_connect = on_connect
client.on_message = on_message

try:
    print(f"🚀 啟動程式，連線至 {MQTT_BROKER}...")
    client.connect(MQTT_BROKER, MQTT_PORT, 60)
    
    # 進入無窮循環監聽
    client.loop_forever()

except KeyboardInterrupt:
    print("\n🛑 程式已手動停止")
    client.disconnect()
except Exception as e:
    print(f"💥 程式異常中斷: {e}")
