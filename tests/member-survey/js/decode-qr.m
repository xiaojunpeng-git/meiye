// Decode the downloaded image locally; never navigate to its URL.
#import <Foundation/Foundation.h>
#import <Vision/Vision.h>
int main(int argc, const char *argv[]) {
 @autoreleasepool {
  if (argc != 2) return 2;
  NSURL *url = [NSURL fileURLWithPath:[NSString stringWithUTF8String:argv[1]]];
  VNDetectBarcodesRequest *request = [[VNDetectBarcodesRequest alloc] init];
  request.symbologies = @[VNBarcodeSymbologyQR];
  VNImageRequestHandler *handler = [[VNImageRequestHandler alloc] initWithURL:url options:@{}];
  NSError *error = nil;
  if (![handler performRequests:@[request] error:&error]) return 3;
  VNBarcodeObservation *result = request.results.firstObject;
  NSString *expected = [@"https://example.invalid/pages/users/survey/index?entry=" stringByAppendingString:[@"" stringByPaddingToLength:48 withString:@"a" startingAtIndex:0]];
  if (![result.payloadStringValue isEqualToString:expected]) return 4;
  puts("QR_DECODE_PASS");
 }
 return 0;
}
