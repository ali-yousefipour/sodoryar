import unittest
from simulator import simulate

class SimulatorTests(unittest.TestCase):
    def test_outcomes(self):
        for outcome in ("approved","declined","cancelled","unknown"):
            self.assertEqual(simulate({"psp":"simulator","connection_mode":"simulator"},outcome),outcome)
    def test_real_psp_is_never_simulated(self):
        for psp in ("behpardakht","fanava","parsian","other"):
            self.assertEqual(simulate({"psp":psp,"connection_mode":"simulator"},"approved"),"unknown")
    def test_wrong_mode(self):
        self.assertEqual(simulate({"psp":"simulator","connection_mode":"lan"},"approved"),"unknown")
    def test_missing_metadata(self):
        self.assertEqual(simulate({},"approved"),"unknown")

if __name__ == "__main__":
    unittest.main()
