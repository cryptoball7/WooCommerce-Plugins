import requests
import json
from requests.auth import HTTPBasicAuth
from jsonschema import validate

import os
from dotenv import load_dotenv

load_dotenv()

DOMAIN = os.getenv('DOMAIN')
DOMAIN = DOMAIN and DOMAIN or "localhost"

BASE_URL = "https://"+os.getenv('DOMAIN')+"/wp-json/agent-commerce/v1"

PEM_PATH = os.getenv('PEM_PATH')
PEM_PATH = PEM_PATH and PEM_PATH or True

WC_KEY = "ck_xxxxxxxxx"
WC_SECRET = "cs_xxxxxxxxx"

session = requests.Session()
session.verify = PEM_PATH

TEST_PRODUCT_ID = os.getenv('TEST_PRODUCT_ID')
TEST_PRODUCT_ID = TEST_PRODUCT_ID and TEST_PRODUCT_ID or 123

CUSTOMER_ID = 1

AGENT_ID = "agent_test"

auth = HTTPBasicAuth(WC_KEY, WC_SECRET)

session_id = None


def print_result(name, success):
    if success:
        print(f"[PASS] {name}")
    else:
        print(f"[FAIL] {name}")


def test_catalog_search():
    url = f"{BASE_URL}/catalog/products"

    r = session.get(url, auth=auth)

    success = r.status_code == 200 and "products" in r.json()

    print_result("Catalog Search", success)

    return r.json()


def test_product_details():
    url = f"{BASE_URL}/catalog/products/{TEST_PRODUCT_ID}"

    r = session.get(url, auth=auth)

    success = r.status_code == 200 and "product" in r.json()

    print_result("Product Details", success)

    return r.json()


def create_checkout_session():
    global session_id

    url = f"{BASE_URL}/checkout/sessions"

    payload = {
        "agent_id": AGENT_ID,
        "customer_id": CUSTOMER_ID,
        "items": [
            {
                "product_id": TEST_PRODUCT_ID,
                "quantity": 1
            }
        ]
    }

    r = session.post(url, auth=auth, json=payload)

    data = r.json()

    success = r.status_code == 200 and "session_id" in data

    if success:
        session_id = data["session_id"]

    print_result("Create Checkout Session", success)

    return data


def quote_session():
    global session_id

    url = f"{BASE_URL}/checkout/sessions/{session_id}/quote"

    r = session.post(url, auth=auth)

    success = r.status_code == 200 and "price_locked_until" in r.json()

    print_result("Quote Session", success)

    return r.json()


def authorize_payment():
    global session_id

    url = f"{BASE_URL}/checkout/sessions/{session_id}/authorize"

    payload = {
        "payment_token": "tok_test"
    }

    r = session.post(url, auth=auth, json=payload)

    success = r.status_code in [200, 201]

    print_result("Authorize Payment", success)

    return r.json()


def complete_checkout():
    global session_id

    url = f"{BASE_URL}/checkout/sessions/{session_id}/complete"

    r = session.post(url, auth=auth)

    success = r.status_code == 200 and "order_id" in r.json()

    print_result("Complete Checkout", success)

    return r.json()

def my_inits():
    import urllib3
    urllib3.disable_warnings(urllib3.exceptions.SubjectAltNameWarning)

def my_tests():
    print("\n--- Performing Custom Tests ---")

    url = f"{BASE_URL}/catalog/products/91"

    r = session.get(url, auth=auth)

    print(r.text)

    success = r.status_code == 200 and "product" in r.json()

    print_result("Product 91 Details", success)
    print(r)
    
    data = create_checkout_session()
    print(data)

    data = authorize_payment()
    print(data)


def run_full_flow():

    my_inits()

    my_tests()

    print("\n--- Testing Catalog ---")

    test_catalog_search()
    test_product_details()

    print("\n--- Testing Checkout Flow ---")

    create_checkout_session()
    quote_session()
    authorize_payment()
    complete_checkout()


if __name__ == "__main__":
    run_full_flow()
