"""Isolated test bridge. Never performs or confirms bank payments."""
import argparse
import json
import os
import time
import urllib.request
import urllib.parse

def request(url, token, body):
    req = urllib.request.Request(url, data=json.dumps(body).encode(),
        headers={"Authorization": "Bearer " + token, "Content-Type": "application/json"},
        method="POST")
    with urllib.request.urlopen(req, timeout=15) as response:
        return json.load(response)

def simulate(command, outcome):
    if command.get("psp") != "simulator" or command.get("connection_mode") != "simulator":
        return "unknown"
    return outcome

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--url", required=True)
    parser.add_argument("--outcome", choices=["approved","declined","cancelled","unknown"], default="unknown")
    parser.add_argument("--once", action="store_true")
    args = parser.parse_args()
    url = urllib.parse.urlsplit(args.url)
    if url.scheme != "https" or not url.hostname or url.username or url.password:
        parser.error("A trusted HTTPS endpoint is required")
    token = os.environ.get("SODORYAR_POS_TOKEN", "")
    if len(token) < 32:
        parser.error("Set SODORYAR_POS_TOKEN")
    while True:
        try:
            command = request(args.url, token, {"action":"poll"}).get("command")
            if command:
                outcome = simulate(command, args.outcome)
                request(args.url, token, {"action":"report","request_id":command["id"],
                    "amount_irr":str(command["amount_irr"]), "result":outcome})
                print("Test result submitted; no financial confirmation.")
        except Exception as exc:
            print("Transport unavailable:", type(exc).__name__)
        if args.once:
            break
        time.sleep(3)

if __name__ == "__main__":
    main()
