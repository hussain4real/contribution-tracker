import base64
import importlib.util
import io
import json
import tempfile
import time
from unittest.mock import patch
from pathlib import Path
import unittest

spec=importlib.util.spec_from_file_location('oauth_probe',Path(__file__).with_name('production-oauth-live-probe.py'))
probe=importlib.util.module_from_spec(spec);spec.loader.exec_module(probe)


class Raw(io.BytesIO):
    def read(self, size=-1, decode_content=False):
        return super().read(size)


def token(claims):
    return 'synthetic.'+base64.urlsafe_b64encode(json.dumps(claims).encode()).decode().rstrip('=')+'.signature'


class ProbeBoundaryTest(unittest.TestCase):
    def test_only_the_selected_existing_client_and_owner_can_be_forwarded(self):
        good=token({'aud':['expected-client'],'sub':'1'})
        self.assertEqual(probe.selected_token('Bearer '+good,'expected-client',1),good)
        for value in [None,'Basic fixture','Bearer invalid','Bearer '+token({'aud':['other-client'],'sub':'1'}),'Bearer '+token({'aud':['expected-client'],'sub':'2'}),'Bearer '+'a'*8193]:
            self.assertIsNone(probe.selected_token(value,'expected-client',1))

    def test_only_a_fixed_private_authentication_request_is_sent_and_no_bearer_is_returned(self):
        config={'basic_authorization':'Basic fixture','native_admin':'admin-fixture','client_id':'expected-client','user_id':1}
        calls=[]
        class Response:
            status_code=200
            raw=Raw(json.dumps({'authenticated':True,'user_id':1,'client_id':'expected-client','token_reference_sha256':'a'*64,'raw_token':'private-token'}).encode())
            def close(self):pass
        def send(url,**kwargs):calls.append((url,kwargs));return Response()
        result=probe.inspect_token('private-token',config,send)
        self.assertTrue(result['authenticated']);self.assertNotIn('private-token',json.dumps(result))
        self.assertEqual(calls[0][0],probe.UPSTREAM);self.assertEqual(calls[0][1]['json'],{'token':'private-token'})
        self.assertFalse(calls[0][1]['allow_redirects']);self.assertTrue(result['read_only'])

    def test_only_one_distinct_matching_bearer_can_be_observed(self):
        with tempfile.TemporaryDirectory() as directory:
            h=object.__new__(probe.Probe)
            h.path='/check';h.client_address=('127.0.0.1',1);h.deadline=time.monotonic()+100
            h.config={'client_id':'expected-client','user_id':1};h.result_file=Path(directory)/'result.json'
            h.send_response=lambda *_:None;h.send_header=lambda *_:None;h.end_headers=lambda:None
            probe.Probe.attempts=0;probe.Probe.selected_fingerprint=None
            first=token({'aud':['expected-client'],'sub':'1','jti':'first'})
            second=token({'aud':['expected-client'],'sub':'1','jti':'second'})
            with patch.object(probe,'inspect_token',return_value={'authenticated':False,'raw_token_saved_or_logged':False}) as inspect:
                h.headers={'Authorization':'Bearer '+first};h.do_POST()
                h.headers={'Authorization':'Bearer '+second};h.do_POST()
                self.assertEqual(inspect.call_count,1)
                h.headers={'Authorization':'Bearer '+first};h.do_POST()
                self.assertEqual(inspect.call_count,2)
            saved=h.result_file.read_text()
            self.assertNotIn(first,saved);self.assertNotIn(second,saved)
            self.assertEqual(len(json.loads(saved)['attempts']),2)

    def test_upstream_redirects_and_wrong_identities_never_count_as_compatible(self):
        config={'basic_authorization':'Basic fixture','native_admin':'admin-fixture','client_id':'expected-client','user_id':1}
        class Response:
            status_code=302
            raw=Raw(b'private-error-detail')
            def close(self):pass
        self.assertFalse(probe.inspect_token('synthetic-token',config,lambda *a,**kw:Response())['authenticated'])
        class Wrong(Response):
            status_code=200
            raw=Raw(b'{"authenticated":true,"user_id":2,"client_id":"other-client"}')
        self.assertFalse(probe.inspect_token('synthetic-token',config,lambda *a,**kw:Wrong())['authenticated'])


if __name__=='__main__':unittest.main()
