#include "rapidocrlite/rapidocrlite.hpp"
#include <iomanip>
#include <iostream>
#include <sstream>
#include <string>

static std::string quote(const std::string& value) {
  std::ostringstream out; out << '"';
  for (unsigned char c : value) {
    if (c == '"' || c == '\\') out << '\\' << c;
    else if (c < 0x20) out << "\\u" << std::hex << std::setw(4) << std::setfill('0') << int(c) << std::dec;
    else out << c;
  }
  out << '"'; return out.str();
}

int main(int argc, char** argv) {
  if (argc != 5) { std::cerr << "usage: receipt-ocr DET.onnx REC.onnx DICT.txt IMAGE.ppm\n"; return 2; }
  try {
    rpocr::Options options;
    options.backend = rpocr::Backend::cpu_only;
    options.model_type = rpocr::ModelType::ppocr;
    options.use_cls = false;
    options.det_threshold = .3F;
    options.det_box_threshold = .5F;
    options.det_unclip_ratio = 1.6F;
    options.det_limit_side_len = 960;
    options.det_limit_type = 1;
    options.det_mean = {.5F,.5F,.5F};
    options.det_std = {.5F,.5F,.5F};
    options.det_use_dilation = 1;
    options.rec_batch_size = 6;
    options.rec_parallelism = 2;
    options.rec_fast_mode = false;
    rpocr::OCR ocr(argv[1], argv[2], argv[3], options);
    auto image = rpocr::LoadPPM(argv[4]);
    auto results = ocr.Recognize(image);
    std::cout << "[";
    bool first = true;
    for (const auto& result : results) {
      if (result.confidence < .5F || result.text.empty()) continue;
      if (!first) std::cout << ",";
      first = false;
      std::cout << "{\"text\":" << quote(result.text) << ",\"confidence\":" << std::setprecision(8) << result.confidence << ",\"polygon\":[";
      for (int i=0; i<4; ++i) { if(i) std::cout << ','; std::cout << '[' << result.box[i][0] << ',' << result.box[i][1] << ']'; }
      std::cout << "]}";
    }
    std::cout << "]\n";
  } catch (const std::exception& error) { std::cerr << error.what() << '\n'; return 1; }
  return 0;
}
