# POS simulator

Python 3.10+. Run `python -m unittest discover -s pos-bridge -p "test_*.py" -v`.

Use only an isolated HTTPS test host. Set SODORYAR_POS_TOKEN securely in the environment. Run `python pos-bridge/simulator.py --url https://YOUR-TEST-HOST/pos_bridge_api.php --outcome approved --once`.

No real bank payments or financial posting. Not a production PSP adapter or Windows installer.
